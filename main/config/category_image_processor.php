<?php
/**
 * Category image processing (food CATEGORY pictures only — never dish photos,
 * banners, logos or anything else).
 *
 * Turns an uploaded category picture into a consistent square image for the
 * circular category buttons on the customer website:
 *
 *  1. decode (JPEG / PNG / GIF / WebP) and fix JPEG camera orientation;
 *  2. if the picture sits on a PLAIN background (white, grey or any solid
 *     colour, detected from the border pixels), remove it: flood-fill from the
 *     edges with a colour tolerance, then a soft alpha ramp at the boundary so
 *     there are no white halos or jagged edges;
 *  3. pictures that already have a transparent background are kept as they are;
 *  4. trim empty margins around the subject and centre it on a square canvas
 *     with balanced padding (transparent where the background was removed);
 *  5. busy photographs (no plain background) are NOT cut out — removing a
 *     real-world background needs an AI service, which this does not pretend to
 *     be — they get a centred square crop instead;
 *  6. export as WebP (keeps transparency), 512×512.
 *
 * Everything is defensive: if the GD image library is missing or anything
 * fails, the original upload is returned unchanged with status "original".
 * Processing happens once, at upload time; the result is stored, never
 * regenerated per page view.
 */

if (!function_exists('processCategoryImage')) {

    define('CATEGORY_IMAGE_SIZE', 512);         // output canvas (px)
    define('CATEGORY_IMAGE_WORK_MAX', 640);     // longest side while processing (keeps memory modest)
    define('CATEGORY_IMAGE_MAX_PIXELS', 40000000); // refuse absurd dimensions (decompression bombs)

    /**
     * @return array{data:string, mime:string, status:string, message:string}
     *   status: background_removed | already_transparent | cropped | original
     */
    function processCategoryImage(string $data, string $mime): array
    {
        $original = ['data' => $data, 'mime' => $mime, 'status' => 'original',
                     'message' => 'Saved as uploaded.'];
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            $original['message'] = 'Saved as uploaded (image processing is not available on this server).';
            return $original;
        }
        $info = @getimagesizefromstring($data);
        if (!$info || ($info[0] * $info[1]) > CATEGORY_IMAGE_MAX_PIXELS) return $original;

        try {
            $src = @imagecreatefromstring($data);
            if (!$src) return $original;
            if (!imageistruecolor($src)) imagepalettetotruecolor($src);
            imagealphablending($src, false);
            imagesavealpha($src, true);
            if ($mime === 'image/jpeg') $src = categoryImageFixOrientation($src, $data);

            $img = categoryImageScale($src, CATEGORY_IMAGE_WORK_MAX);
            $w = imagesx($img); $h = imagesy($img);

            if (categoryImageHasTransparentEdges($img)) {
                $status = 'already_transparent';
                $message = 'Transparent picture detected: trimmed and centred.';
            } else {
                $bg = categoryImagePlainBackground($img);
                $status = 'cropped';
                $message = 'No plain background to remove: centred and cropped to fit the circle.';
                if ($bg !== null) {
                    $cut = categoryImageRemoveBackground($img, $bg);
                    if ($cut !== null) {
                        $img = $cut;
                        $status = 'background_removed';
                        $message = 'Background removed, trimmed and centred.';
                    } else {
                        $message = 'Background could not be removed safely: centred and cropped instead.';
                    }
                }
            }

            if ($status === 'cropped') {
                $out = categoryImageCoverSquare($img, CATEGORY_IMAGE_SIZE);
            } else {
                $out = categoryImageCenterOnCanvas($img, CATEGORY_IMAGE_SIZE, 0.08);
            }
            if ($out === null) return $original;

            ob_start();
            $ok = imagewebp($out, null, 86);
            $webp = ob_get_clean();
            if (!$ok || $webp === '' || $webp === false) return $original;
            return ['data' => $webp, 'mime' => 'image/webp', 'status' => $status, 'message' => $message];
        } catch (Throwable $e) {
            error_log('processCategoryImage: ' . $e->getMessage());
            return $original;
        }
    }

    /** Apply EXIF orientation (phone photos) when the exif extension is available. */
    function categoryImageFixOrientation($img, string $data)
    {
        if (!function_exists('exif_read_data')) return $img;
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($data));
        $o = (int)($exif['Orientation'] ?? 1);
        $map = [3 => 180, 6 => -90, 8 => 90];
        if (isset($map[$o])) {
            $rot = imagerotate($img, $map[$o], 0);
            if ($rot) return $rot;
        }
        return $img;
    }

    /** Downscale so the longest side is at most $max (keeps alpha). */
    function categoryImageScale($img, int $max)
    {
        $w = imagesx($img); $h = imagesy($img);
        $s = min(1, $max / max($w, $h));
        if ($s >= 1) return $img;
        $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $dst;
    }

    /** Border pixels, sampled (array of [r,g,b,a]). */
    function categoryImageBorderPixels($img): array
    {
        $w = imagesx($img); $h = imagesy($img);
        $step = max(1, (int)floor(($w + $h) / 400));
        $px = [];
        for ($x = 0; $x < $w; $x += $step) { $px[] = imagecolorat($img, $x, 0); $px[] = imagecolorat($img, $x, $h - 1); }
        for ($y = 0; $y < $h; $y += $step) { $px[] = imagecolorat($img, 0, $y); $px[] = imagecolorat($img, $w - 1, $y); }
        return array_map(function ($c) {
            return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255, ($c >> 24) & 127];
        }, $px);
    }

    /** Most of the border already transparent → it's a cut-out already. */
    function categoryImageHasTransparentEdges($img): bool
    {
        $px = categoryImageBorderPixels($img);
        $t = 0;
        foreach ($px as $p) if ($p[3] > 100) $t++;
        return $px && $t / count($px) >= 0.6;
    }

    /**
     * The background colour if the border is (almost) one even colour, else null.
     * Uses the per-channel median of the border as the candidate colour and
     * requires ≥ 88 % of border pixels to be close to it.
     */
    function categoryImagePlainBackground($img): ?array
    {
        $px = categoryImageBorderPixels($img);
        if (count($px) < 8) return null;
        $med = [];
        for ($c = 0; $c < 3; $c++) {
            $col = array_column($px, $c);
            sort($col);
            $med[$c] = $col[(int)(count($col) / 2)];
        }
        $close = 0;
        foreach ($px as $p) {
            if (categoryImageDist($p, $med) <= 30) $close++;
        }
        return ($close / count($px) >= 0.88) ? $med : null;
    }

    function categoryImageDist(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /**
     * Flood-fill the background from every edge pixel (colour distance ≤ $hard),
     * then soften the boundary: pixels next to the background with distance up
     * to $soft get partial transparency. Returns null when the result looks
     * unsafe (almost everything or almost nothing removed).
     */
    function categoryImageRemoveBackground($img, array $bg, int $hard = 30, int $soft = 52)
    {
        $w = imagesx($img); $h = imagesy($img);
        $n = $w * $h;
        $dist = new SplFixedArray($n);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);
                $dist[$y * $w + $x] = categoryImageDist([($c >> 16) & 255, ($c >> 8) & 255, $c & 255], $bg);
            }
        }
        // BFS flood fill from the borders through "background-like" pixels
        $isBg = array_fill(0, $n, false);
        $queue = new SplQueue();
        $push = function ($x, $y) use (&$isBg, $queue, $dist, $w, $hard) {
            $i = $y * $w + $x;
            if (!$isBg[$i] && $dist[$i] <= $hard) { $isBg[$i] = true; $queue->enqueue($i); }
        };
        for ($x = 0; $x < $w; $x++) { $push($x, 0); $push($x, $h - 1); }
        for ($y = 0; $y < $h; $y++) { $push(0, $y); $push($w - 1, $y); }
        $removed = 0;
        while (!$queue->isEmpty()) {
            $i = $queue->dequeue(); $removed++;
            $x = $i % $w; $y = intdiv($i, $w);
            if ($x > 0) $push($x - 1, $y);
            if ($x < $w - 1) $push($x + 1, $y);
            if ($y > 0) $push($x, $y - 1);
            if ($y < $h - 1) $push($x, $y + 1);
        }
        $frac = $removed / $n;
        if ($frac < 0.04 || $frac > 0.93) return null;

        // Drop small detached specks (bits of plate rim, crumbs, noise left
        // floating after the cut): keep foreground pieces at least 3% the size
        // of the largest one (so a second real item, e.g. a side bowl, stays).
        $label = array_fill(0, $n, 0);
        $sizes = [0];
        $comp = 0;
        for ($start = 0; $start < $n; $start++) {
            if ($isBg[$start] || $label[$start]) continue;
            $comp++; $size = 0;
            $stack = [$start]; $label[$start] = $comp;
            while ($stack) {
                $i = array_pop($stack); $size++;
                $x = $i % $w; $y = intdiv($i, $w);
                foreach ([$x > 0 ? $i - 1 : -1, $x < $w - 1 ? $i + 1 : -1, $y > 0 ? $i - $w : -1, $y < $h - 1 ? $i + $w : -1] as $j) {
                    if ($j >= 0 && !$isBg[$j] && !$label[$j]) { $label[$j] = $comp; $stack[] = $j; }
                }
            }
            $sizes[$comp] = $size;
        }
        $keepMin = max($sizes) * 0.03;
        for ($i = 0; $i < $n; $i++) {
            if (!$isBg[$i] && $sizes[$label[$i]] < $keepMin) $isBg[$i] = true;
        }

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false); imagesavealpha($out, true);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $i = $y * $w + $x;
                $c = imagecolorat($img, $x, $y);
                $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
                if ($isBg[$i]) { imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, 127)); continue; }
                $alpha = 0; // GD: 0 = opaque, 127 = transparent
                $touchesBg = ($x > 0 && $isBg[$i - 1]) || ($x < $w - 1 && $isBg[$i + 1])
                          || ($y > 0 && $isBg[$i - $w]) || ($y < $h - 1 && $isBg[$i + $w]);
                if ($touchesBg && $dist[$i] < $soft) {
                    // Soft edge: closer to the background colour = more transparent
                    $t = ($soft - $dist[$i]) / ($soft - $hard);
                    $alpha = (int)round(max(0, min(1, $t)) * 110);
                    if ($alpha > 0) {
                        // Pull the background colour out of the edge pixel (no halo)
                        $k = 1 - $alpha / 127;
                        if ($k > 0.05) {
                            $r = (int)max(0, min(255, ($r - $bg[0] * (1 - $k)) / $k));
                            $g = (int)max(0, min(255, ($g - $bg[1] * (1 - $k)) / $k));
                            $b = (int)max(0, min(255, ($b - $bg[2] * (1 - $k)) / $k));
                        }
                    }
                }
                $orig = ($c >> 24) & 127;
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, max($alpha, $orig)));
            }
        }
        return $out;
    }

    /** Bounding box of non-transparent pixels: [x0, y0, x1, y1] or null. */
    function categoryImageOpaqueBox($img): ?array
    {
        $w = imagesx($img); $h = imagesy($img);
        $x0 = $w; $y0 = $h; $x1 = -1; $y1 = -1;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (((imagecolorat($img, $x, $y) >> 24) & 127) < 100) {
                    if ($x < $x0) $x0 = $x; if ($x > $x1) $x1 = $x;
                    if ($y < $y0) $y0 = $y; if ($y > $y1) $y1 = $y;
                }
            }
        }
        return $x1 < 0 ? null : [$x0, $y0, $x1, $y1];
    }

    /** Trim to the subject and centre it on a transparent square with padding. */
    function categoryImageCenterOnCanvas($img, int $size, float $pad)
    {
        $box = categoryImageOpaqueBox($img);
        if (!$box) return null;
        [$x0, $y0, $x1, $y1] = $box;
        $bw = $x1 - $x0 + 1; $bh = $y1 - $y0 + 1;
        $inner = $size * (1 - 2 * $pad);
        $s = $inner / max($bw, $bh);
        $dw = (int)round($bw * $s); $dh = (int)round($bh * $s);
        $out = imagecreatetruecolor($size, $size);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagealphablending($out, true);
        imagecopyresampled($out, $img, (int)(($size - $dw) / 2), (int)(($size - $dh) / 2), $x0, $y0, $dw, $dh, $bw, $bh);
        imagealphablending($out, false);
        return $out;
    }

    /** Centred square crop of a photo (fills the circle; no stretching). */
    function categoryImageCoverSquare($img, int $size)
    {
        $w = imagesx($img); $h = imagesy($img);
        $side = min($w, $h);
        $out = imagecreatetruecolor($size, $size);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagecopyresampled($out, $img, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), $size, $size, $side, $side);
        return $out;
    }
}
