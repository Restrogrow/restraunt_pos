<?php
/**
 * Attendance and payroll: who worked which day, and what that comes to at the end of the month.
 *
 * `attendance` is one row per person per day — the day's status, and the punch in / out when the shift
 * was clocked rather than just marked. One row per (restaurant, staff, date) is enforced by a unique
 * key, so marking the same day twice corrects it instead of counting it twice.
 *
 * Salary lives on the staff member (`staff.salary_type`, `staff.salary_amount`, added on first use):
 *   monthly  a fixed salary; an absent day costs one day of it (salary ÷ days in the month)
 *   daily    a day's wage per day actually worked
 *   hourly   a rate per hour, from the punch in / out
 *
 * Statuses and what they are worth:
 *   present 1 day · half 0.5 · absent 0 · paid_leave 1 · week_off and holiday 1 on a monthly salary,
 *   0 on a daily wage (nobody is paid a day's wage for a day the place was shut).
 *
 * `payroll_entries` is the month's advances, deductions and bonuses. `payroll_runs` is a month marked
 * paid: the figures are frozen into it, so a payslip printed later never changes because attendance was
 * corrected afterwards. A month already marked paid is refused a second time; it has to be reopened.
 *
 * Nothing here touches the cash drawer on its own. A salary paid out in cash is a cash movement the
 * cashier records in the cash book (config/cash_session.php) — payroll says what is owed, the drawer
 * says what left it, and keeping them apart is what lets them be checked against each other.
 */

if (!function_exists('atEnsure')) {

    define('AT_STATUSES', ['present', 'half', 'absent', 'paid_leave', 'week_off', 'holiday']);
    define('AT_STATUS_LABELS', ['present' => 'Present', 'half' => 'Half day', 'absent' => 'Absent',
                                'paid_leave' => 'Paid leave', 'week_off' => 'Week off', 'holiday' => 'Holiday']);
    define('AT_SALARY_TYPES', ['monthly', 'daily', 'hourly']);
    // How those two are written for a person to read. The statuses above have had labels from the
    // start; a salary type and a payroll entry type never did, so the stored word went on the screen
    // as it is — Payroll read "Waiter · monthly ₹18,000", the Salaries dropdown listed
    // monthly / daily / hourly, and an advance showed as "· advance". The keys are unchanged.
    define('AT_SALARY_TYPE_LABELS', ['monthly' => 'Monthly salary', 'daily' => 'Per day', 'hourly' => 'Per hour']);
    define('PL_ENTRY_TYPE_LABELS', ['advance' => 'Advance', 'deduction' => 'Deduction', 'bonus' => 'Bonus']);

    function atEnsure(PDO $conn): void
    {
        static $done = false;
        if ($done || $conn->inTransaction()) return;
        $done = true;
        require_once __DIR__ . '/schema_cache.php';
        try {
            if (!rgHasTable($conn, 'attendance')) {
                $conn->exec("CREATE TABLE IF NOT EXISTS attendance (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    restaurant_id VARCHAR(50) NOT NULL,
                    staff_id INT NOT NULL,
                    staff_name VARCHAR(120) NOT NULL DEFAULT '',
                    work_date DATE NOT NULL,
                    status ENUM('present','half','absent','paid_leave','week_off','holiday') NOT NULL DEFAULT 'present',
                    punch_in DATETIME DEFAULT NULL,
                    punch_out DATETIME DEFAULT NULL,
                    minutes INT NOT NULL DEFAULT 0,
                    overtime_minutes INT NOT NULL DEFAULT 0,
                    note VARCHAR(255) DEFAULT NULL,
                    marked_by_name VARCHAR(120) NOT NULL DEFAULT '',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_at_day (restaurant_id, staff_id, work_date),
                    INDEX idx_at_rest (restaurant_id, work_date),
                    INDEX idx_at_staff (staff_id, work_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            if (!rgHasTable($conn, 'payroll_entries')) {
                $conn->exec("CREATE TABLE IF NOT EXISTS payroll_entries (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    restaurant_id VARCHAR(50) NOT NULL,
                    staff_id INT NOT NULL,
                    period CHAR(7) NOT NULL,
                    type ENUM('advance','deduction','bonus') NOT NULL DEFAULT 'advance',
                    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    note VARCHAR(255) DEFAULT NULL,
                    staff_name VARCHAR(120) NOT NULL DEFAULT '',
                    by_name VARCHAR(120) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL,
                    INDEX idx_pe_period (restaurant_id, period),
                    INDEX idx_pe_staff (staff_id, period)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            if (!rgHasTable($conn, 'payroll_runs')) {
                $conn->exec("CREATE TABLE IF NOT EXISTS payroll_runs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    restaurant_id VARCHAR(50) NOT NULL,
                    staff_id INT NOT NULL,
                    staff_name VARCHAR(120) NOT NULL DEFAULT '',
                    period CHAR(7) NOT NULL,
                    gross DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    advances DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    bonus DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    net DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    pay_method VARCHAR(40) DEFAULT NULL,
                    snapshot LONGTEXT NULL,
                    paid_by_name VARCHAR(120) NOT NULL DEFAULT '',
                    paid_at DATETIME NOT NULL,
                    UNIQUE KEY uq_pr_run (restaurant_id, staff_id, period),
                    INDEX idx_pr_period (restaurant_id, period)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            foreach ([['salary_type', "ENUM('monthly','daily','hourly') NOT NULL DEFAULT 'monthly'"],
                      ['salary_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00'],
                      ['joined_on', 'DATE DEFAULT NULL']] as [$col, $def]) {
                if (!rgHasColumn($conn, 'staff', $col)) $conn->exec("ALTER TABLE staff ADD COLUMN `$col` $def");
            }
        } catch (PDOException $e) {
            error_log('atEnsure: ' . $e->getMessage());
        }
    }

    function atToday(PDO $conn, string $rid): string
    {
        require_once __DIR__ . '/cash_session.php';
        return (new DateTime('today', csTz($conn, $rid)))->format('Y-m-d');
    }

    /** 'YYYY-MM', this month when anything else is passed. */
    function atPeriod(PDO $conn, string $rid, $raw): string
    {
        $raw = (string)$raw;
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) return $raw;
        require_once __DIR__ . '/cash_session.php';
        return (new DateTime('today', csTz($conn, $rid)))->format('Y-m');
    }

    function atDay($raw, string $fallback): string
    {
        return (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) && DateTime::createFromFormat('!Y-m-d', $raw)) ? $raw : $fallback;
    }

    /** Everyone on the team, with what they are paid. */
    function atStaff(PDO $conn, string $rid, bool $activeOnly = true): array
    {
        atEnsure($conn);
        $sql = 'SELECT id, member_name, role, phone, is_active, salary_type, salary_amount, joined_on FROM staff WHERE restaurant_id = ?';
        if ($activeOnly) $sql .= ' AND is_active = 1';
        $st = $conn->prepare($sql . ' ORDER BY is_active DESC, member_name');
        $st->execute([$rid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $out[] = ['id' => (int)$s['id'], 'name' => (string)$s['member_name'], 'role' => (string)$s['role'],
                      'phone' => (string)$s['phone'], 'active' => (int)$s['is_active'] === 1,
                      'salary_type' => (string)($s['salary_type'] ?: 'monthly'), 'salary' => round((float)$s['salary_amount'], 2),
                      'joined_on' => $s['joined_on']];
        }
        return $out;
    }

    function atSetSalary(PDO $conn, string $rid, int $staffId, string $type, float $amount, ?string $joined): array
    {
        atEnsure($conn);
        if (!in_array($type, AT_SALARY_TYPES, true)) throw new InvalidArgumentException('Pick monthly, daily or hourly.');
        $amount = round($amount, 2);
        if ($amount < 0 || $amount > 99999999) throw new InvalidArgumentException('Check the salary.');
        $st = $conn->prepare('UPDATE staff SET salary_type = ?, salary_amount = ?, joined_on = ? WHERE id = ? AND restaurant_id = ?');
        $st->execute([$type, $amount, $joined ?: null, $staffId, $rid]);
        if (!$st->rowCount()) {
            $check = $conn->prepare('SELECT id FROM staff WHERE id = ? AND restaurant_id = ?');
            $check->execute([$staffId, $rid]);
            if (!$check->fetchColumn()) throw new InvalidArgumentException('That staff member was not found.');
        }
        return ['id' => $staffId, 'salary_type' => $type, 'salary' => $amount, 'joined_on' => $joined ?: null];
    }

    /** The staff member, or a refusal if they belong to someone else. */
    function atStaffRow(PDO $conn, string $rid, int $staffId): array
    {
        $st = $conn->prepare('SELECT id, member_name, role, salary_type, salary_amount FROM staff WHERE id = ? AND restaurant_id = ?');
        $st->execute([$staffId, $rid]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) throw new InvalidArgumentException('That staff member was not found.');
        return $s;
    }

    /** Mark (or correct) one person's day. Punch times are optional; minutes are worked out from them. */
    function atMark(PDO $conn, string $rid, int $staffId, string $date, string $status, array $extra, array $who): array
    {
        atEnsure($conn);
        $s = atStaffRow($conn, $rid, $staffId);
        if (!in_array($status, AT_STATUSES, true)) throw new InvalidArgumentException('Unknown attendance status.');
        $date = atDay($date, atToday($conn, $rid));
        if ($date > atToday($conn, $rid)) throw new InvalidArgumentException('That day has not happened yet.');
        atRefuseIfPaid($conn, $rid, $staffId, substr($date, 0, 7));

        $in = atStamp($extra['punch_in'] ?? null, $date);
        $out = atStamp($extra['punch_out'] ?? null, $date);
        if ($in && $out && $out < $in) throw new InvalidArgumentException('The punch out is before the punch in.');
        $minutes = isset($extra['minutes']) && is_numeric($extra['minutes'])
            ? max(0, min(24 * 60, (int)$extra['minutes']))
            : (($in && $out) ? (int)round((strtotime($out) - strtotime($in)) / 60) : 0);
        $ot = max(0, min(24 * 60, (int)($extra['overtime_minutes'] ?? 0)));

        $conn->prepare('INSERT INTO attendance (restaurant_id, staff_id, staff_name, work_date, status, punch_in, punch_out, minutes, overtime_minutes, note, marked_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), punch_in = VALUES(punch_in), punch_out = VALUES(punch_out),
                minutes = VALUES(minutes), overtime_minutes = VALUES(overtime_minutes), note = VALUES(note), marked_by_name = VALUES(marked_by_name)')
            ->execute([$rid, $staffId, mb_substr((string)$s['member_name'], 0, 120), $date, $status, $in, $out, $minutes, $ot,
                       mb_substr(trim(strip_tags((string)($extra['note'] ?? ''))), 0, 255) ?: null, mb_substr($who['name'], 0, 120)]);
        return ['staff_id' => $staffId, 'date' => $date, 'status' => $status, 'minutes' => $minutes, 'overtime_minutes' => $ot,
                'punch_in' => $in, 'punch_out' => $out];
    }

    /** 'HH:MM' or a full timestamp on that day -> 'Y-m-d H:i:s', or null. */
    function atStamp($raw, string $date): ?string
    {
        $raw = trim((string)$raw);
        if ($raw === '') return null;
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw)) return $date . ' ' . $raw . ':00';
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]([01]\d|2[0-3]):([0-5]\d)/', $raw)) return str_replace('T', ' ', substr($raw, 0, 16)) . ':00';
        throw new InvalidArgumentException('Enter the time as HH:MM.');
    }

    /**
     * Clock in, or clock out if already clocked in today. What a staff member taps themselves — one
     * button, no form, so it can be used on a phone at the door.
     */
    function atPunch(PDO $conn, string $rid, int $staffId, array $who): array
    {
        atEnsure($conn);
        $s = atStaffRow($conn, $rid, $staffId);
        $today = atToday($conn, $rid);
        atRefuseIfPaid($conn, $rid, $staffId, substr($today, 0, 7));
        require_once __DIR__ . '/cash_session.php';
        $now = csNow($conn, $rid);

        $st = $conn->prepare('SELECT * FROM attendance WHERE restaurant_id = ? AND staff_id = ? AND work_date = ?');
        $st->execute([$rid, $staffId, $today]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row || !$row['punch_in']) {
            $conn->prepare('INSERT INTO attendance (restaurant_id, staff_id, staff_name, work_date, status, punch_in, marked_by_name)
                    VALUES (?, ?, ?, ?, \'present\', ?, ?)
                ON DUPLICATE KEY UPDATE status = \'present\', punch_in = VALUES(punch_in), marked_by_name = VALUES(marked_by_name)')
                ->execute([$rid, $staffId, mb_substr((string)$s['member_name'], 0, 120), $today, $now, mb_substr($who['name'], 0, 120)]);
            return ['punched' => 'in', 'at' => $now, 'date' => $today];
        }
        if ($row['punch_out']) throw new InvalidArgumentException('Today is already clocked in and out. A manager can correct it.');
        $minutes = max(0, (int)round((strtotime($now) - strtotime((string)$row['punch_in'])) / 60));
        $conn->prepare('UPDATE attendance SET punch_out = ?, minutes = ? WHERE id = ?')->execute([$now, $minutes, (int)$row['id']]);
        return ['punched' => 'out', 'at' => $now, 'date' => $today, 'minutes' => $minutes];
    }

    /** A month already paid is frozen: correcting it would make the payslip a lie. */
    function atRefuseIfPaid(PDO $conn, string $rid, int $staffId, string $period): void
    {
        try {
            $st = $conn->prepare('SELECT id FROM payroll_runs WHERE restaurant_id = ? AND staff_id = ? AND period = ? LIMIT 1');
            $st->execute([$rid, $staffId, $period]);
            if ($st->fetchColumn()) {
                throw new InvalidArgumentException('That month is already marked paid. Reopen it first to change anything in it.');
            }
        } catch (PDOException $e) {}
    }

    /** One month's attendance: every marked day for every person, plus each person's day count. */
    function atMonth(PDO $conn, string $rid, string $period): array
    {
        atEnsure($conn);
        $first = $period . '-01';
        $days = (int)(new DateTime($first))->format('t');
        $st = $conn->prepare('SELECT staff_id, work_date, status, punch_in, punch_out, minutes, overtime_minutes, note
            FROM attendance WHERE restaurant_id = ? AND work_date >= ? AND work_date <= ? ORDER BY work_date');
        $st->execute([$rid, $first, $period . '-' . $days]);
        $byStaff = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byStaff[(int)$r['staff_id']][(int)substr($r['work_date'], 8, 2)] = [
                'status' => $r['status'], 'minutes' => (int)$r['minutes'], 'overtime_minutes' => (int)$r['overtime_minutes'],
                'in' => $r['punch_in'] ? substr($r['punch_in'], 11, 5) : null,
                'out' => $r['punch_out'] ? substr($r['punch_out'], 11, 5) : null,
                'note' => (string)($r['note'] ?? ''),
            ];
        }
        $rows = [];
        foreach (atStaff($conn, $rid, false) as $s) {
            $marked = $byStaff[$s['id']] ?? [];
            $rows[] = ['staff' => $s, 'days' => $marked, 'counts' => atCounts($marked)];
        }
        return ['period' => $period, 'days_in_month' => $days, 'staff' => $rows];
    }

    /** How many of each status, and the minutes worked, for one person's month. */
    function atCounts(array $marked): array
    {
        $c = array_fill_keys(AT_STATUSES, 0);
        $minutes = 0;
        $ot = 0;
        foreach ($marked as $d) {
            if (isset($c[$d['status']])) $c[$d['status']]++;
            $minutes += (int)$d['minutes'];
            $ot += (int)$d['overtime_minutes'];
        }
        $c['marked'] = count($marked);
        $c['minutes'] = $minutes;
        $c['overtime_minutes'] = $ot;
        return $c;
    }

    /* ---------- the month's advances, deductions and bonuses ---------- */

    function plAdd(PDO $conn, string $rid, int $staffId, string $period, string $type, float $amount, string $note, array $who): array
    {
        atEnsure($conn);
        $s = atStaffRow($conn, $rid, $staffId);
        if (!in_array($type, ['advance', 'deduction', 'bonus'], true)) throw new InvalidArgumentException('Unknown kind.');
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > 99999999) throw new InvalidArgumentException('Enter the amount.');
        atRefuseIfPaid($conn, $rid, $staffId, $period);
        require_once __DIR__ . '/cash_session.php';
        $conn->prepare('INSERT INTO payroll_entries (restaurant_id, staff_id, period, type, amount, note, staff_name, by_name, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$rid, $staffId, $period, $type, $amount, mb_substr(trim(strip_tags($note)), 0, 255) ?: null,
                       mb_substr((string)$s['member_name'], 0, 120), mb_substr($who['name'], 0, 120), csNow($conn, $rid)]);
        return ['id' => (int)$conn->lastInsertId(), 'type' => $type, 'amount' => $amount];
    }

    function plRemove(PDO $conn, string $rid, int $id): void
    {
        atEnsure($conn);
        $st = $conn->prepare('SELECT staff_id, period FROM payroll_entries WHERE id = ? AND restaurant_id = ?');
        $st->execute([$id, $rid]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) throw new InvalidArgumentException('That entry was not found.');
        atRefuseIfPaid($conn, $rid, (int)$e['staff_id'], (string)$e['period']);
        $conn->prepare('DELETE FROM payroll_entries WHERE id = ? AND restaurant_id = ?')->execute([$id, $rid]);
    }

    function plEntries(PDO $conn, string $rid, string $period): array
    {
        atEnsure($conn);
        $st = $conn->prepare('SELECT * FROM payroll_entries WHERE restaurant_id = ? AND period = ? ORDER BY id DESC');
        $st->execute([$rid, $period]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $out[] = ['id' => (int)$e['id'], 'staff_id' => (int)$e['staff_id'], 'staff' => (string)$e['staff_name'],
                      'type' => $e['type'], 'amount' => round((float)$e['amount'], 2), 'note' => (string)($e['note'] ?? ''),
                      'by' => (string)$e['by_name'], 'at' => $e['created_at']];
        }
        return $out;
    }

    /* ---------- payroll ---------- */

    /**
     * What one person earned in a month, from their attendance and their salary.
     *
     * monthly  salary x payable days / days in the month, where an absent day is not payable and a half
     *          day is half. Days that were never marked are not treated as absent — only a day someone
     *          actually wrote 'absent' on costs money, because a blank is "nobody marked it", not proof
     *          that the person stayed home.
     * daily    the day's wage for each day worked (half day = half). A week off or a holiday pays nothing.
     * hourly   the rate for every hour clocked, from the punch in / out.
     */
    function plFor(array $staff, array $counts, int $daysInMonth): array
    {
        $rate = (float)$staff['salary'];
        $type = $staff['salary_type'];
        $worked = $counts['present'] + 0.5 * $counts['half'];
        $hours = round(($counts['minutes'] + $counts['overtime_minutes']) / 60, 2);

        if ($type === 'hourly') {
            $gross = round($hours * $rate, 2);
            $basis = $hours . ' h';
        } elseif ($type === 'daily') {
            $paidDays = $worked + $counts['paid_leave'];
            $gross = round($paidDays * $rate, 2);
            $basis = rtrim(rtrim(number_format($paidDays, 1), '0'), '.') . ' of ' . $daysInMonth . ' days';
        } else {
            $unpaid = $counts['absent'] + 0.5 * $counts['half'];
            $payable = max(0, $daysInMonth - $unpaid);
            $gross = round($rate * $payable / max(1, $daysInMonth), 2);
            $basis = rtrim(rtrim(number_format($payable, 1), '0'), '.') . ' of ' . $daysInMonth . ' days';
        }
        return ['gross' => $gross, 'basis' => $basis, 'hours' => $hours, 'worked_days' => $worked];
    }

    /** The whole team's payroll for a month, with what is already marked paid. */
    function plPayroll(PDO $conn, string $rid, string $period): array
    {
        $month = atMonth($conn, $rid, $period);
        $entries = plEntries($conn, $rid, $period);
        $runs = [];
        $st = $conn->prepare('SELECT * FROM payroll_runs WHERE restaurant_id = ? AND period = ?');
        $st->execute([$rid, $period]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $runs[(int)$r['staff_id']] = $r;

        $rows = [];
        $tot = ['gross' => 0.0, 'advances' => 0.0, 'deductions' => 0.0, 'bonus' => 0.0, 'net' => 0.0, 'paid' => 0.0];
        foreach ($month['staff'] as $m) {
            $s = $m['staff'];
            $run = $runs[$s['id']] ?? null;
            if ($run) {
                $row = ['staff' => $s, 'counts' => $m['counts'], 'basis' => '',
                        'gross' => round((float)$run['gross'], 2), 'advances' => round((float)$run['advances'], 2),
                        'deductions' => round((float)$run['deductions'], 2), 'bonus' => round((float)$run['bonus'], 2),
                        'net' => round((float)$run['net'], 2), 'paid' => true,
                        'paid_at' => $run['paid_at'], 'paid_by' => (string)$run['paid_by_name'], 'pay_method' => (string)($run['pay_method'] ?? '')];
                $snap = $run['snapshot'] ? json_decode((string)$run['snapshot'], true) : null;
                if (is_array($snap)) {
                    $row['basis'] = (string)($snap['basis'] ?? '');
                    $row['counts'] = is_array($snap['counts'] ?? null) ? $snap['counts'] : $row['counts'];
                }
                $tot['paid'] += $row['net'];
            } else {
                $calc = plFor($s, $m['counts'], $month['days_in_month']);
                $adv = 0.0;
                $ded = 0.0;
                $bon = 0.0;
                foreach ($entries as $e) {
                    if ($e['staff_id'] !== $s['id']) continue;
                    if ($e['type'] === 'advance') $adv += $e['amount'];
                    elseif ($e['type'] === 'deduction') $ded += $e['amount'];
                    else $bon += $e['amount'];
                }
                $net = round($calc['gross'] + $bon - $ded - $adv, 2);
                $row = ['staff' => $s, 'counts' => $m['counts'], 'basis' => $calc['basis'], 'gross' => $calc['gross'],
                        'advances' => round($adv, 2), 'deductions' => round($ded, 2), 'bonus' => round($bon, 2),
                        'net' => $net, 'paid' => false];
            }
            foreach (['gross', 'advances', 'deductions', 'bonus', 'net'] as $k) $tot[$k] += $row[$k];
            $rows[] = $row;
        }
        foreach ($tot as $k => $v) $tot[$k] = round($v, 2);
        return ['period' => $period, 'days_in_month' => $month['days_in_month'], 'rows' => $rows, 'totals' => $tot, 'entries' => $entries];
    }

    /** Freeze one person's month as paid. The figures are stored, not recomputed later. */
    function plMarkPaid(PDO $conn, string $rid, int $staffId, string $period, string $method, array $who): array
    {
        atEnsure($conn);
        atStaffRow($conn, $rid, $staffId);
        $payroll = plPayroll($conn, $rid, $period);
        $row = null;
        foreach ($payroll['rows'] as $r) if ($r['staff']['id'] === $staffId) { $row = $r; break; }
        if (!$row) throw new InvalidArgumentException('That staff member was not found.');
        if (!empty($row['paid'])) throw new InvalidArgumentException('That month is already marked paid.');
        $calc = plFor($row['staff'], $row['counts'], $payroll['days_in_month']);
        require_once __DIR__ . '/cash_session.php';
        $conn->prepare('INSERT INTO payroll_runs (restaurant_id, staff_id, staff_name, period, gross, advances, deductions, bonus, net,
                pay_method, snapshot, paid_by_name, paid_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$rid, $staffId, mb_substr($row['staff']['name'], 0, 120), $period, $row['gross'], $row['advances'],
                       $row['deductions'], $row['bonus'], $row['net'], mb_substr(trim($method) ?: 'Cash', 0, 40),
                       json_encode(['basis' => $calc['basis'], 'counts' => $row['counts'], 'salary_type' => $row['staff']['salary_type'],
                                    'salary' => $row['staff']['salary']], JSON_UNESCAPED_UNICODE),
                       mb_substr($who['name'], 0, 120), csNow($conn, $rid)]);
        return ['staff_id' => $staffId, 'period' => $period, 'net' => $row['net']];
    }

    /** Undo a paid month, so attendance and entries can be corrected and it can be paid again. */
    function plReopen(PDO $conn, string $rid, int $staffId, string $period): void
    {
        atEnsure($conn);
        $st = $conn->prepare('DELETE FROM payroll_runs WHERE restaurant_id = ? AND staff_id = ? AND period = ?');
        $st->execute([$rid, $staffId, $period]);
        if (!$st->rowCount()) throw new InvalidArgumentException('That month is not marked paid.');
    }
}
