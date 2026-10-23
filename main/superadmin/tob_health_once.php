<?php
/**
 * ONE-TIME (Super Admin only), delete after use: Taste of बनारस (RESKAW893) nutrition + plan details.
 * Fills protein / fat / carbs / fibre, health tags and ingredients on dishes that have none yet,
 * calories where they are 0, and a description + photo on meal plans that have none.
 * Never overwrites what the owner already filled. Prices, meals and the weekly menu are not touched.
 * GET shows what it would do; the button POSTs (CSRF) and applies it.
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/ingredient_helpers.php';

const TOB_RID = 'RESKAW893';
// dish id => [kcal if missing, protein, fat, carbs, fibre, tags, ingredients]
const TOB_DISHES = [
    4132 => [0, 3, 9, 30, 3, ['vegan', 'gluten_free'], "Potato - fried crisp\nBanarasi chaat masala\nTamarind & mint chutney\nOnion & coriander\nLemon"],
    4147 => [0, 3, 2, 7, 0, ['low_calorie', 'low_fat', 'low_sugar', 'gluten_free'], "Buttermilk - probiotic, aids digestion\nRoasted cumin\nCoriander & mint"],
    4222 => [340, 8, 17, 39, 3, [], "Samosa (2) - spiced potato and peas\nMasala chhach - probiotic buttermilk"],
    4224 => [490, 10, 20, 67, 5, [], "Veg sandwich - vegetables and green chutney\nFrench fries\nMasala chhach - probiotic buttermilk"],
    3917 => [0, 10, 11, 60, 2, ['gluten_free'], "Kadhi - curd and gram flour curry\nPakodi\nSteamed basmati rice\nCumin & curry leaf tempering"],
    3926 => [0, 4, 13, 42, 1, [], "Khoya (milk solids)\nSugar syrup with cardamom"],
    3934 => [0, 22, 26, 107, 12, ['jain', 'high_protein', 'high_fibre'], "Jain dal - no onion, no garlic\nJain seasonal sabzi\nRotis & rice\nSalad, pickle & papad\nCooked as per Jain rules"],
    3911 => [0, 16, 25, 15, 2, ['high_protein', 'low_carb', 'gluten_free'], "Paneer - protein and calcium\nOnion-tomato gravy\nWhole spices\nCream"],
    3918 => [0, 2, 0, 8, 3, ['vegan', 'low_calorie', 'low_fat', 'gluten_free'], "Cucumber\nTomato\nOnion\nCarrot\nLemon"],
    4133 => [0, 4, 8, 23, 3, [], "Tomato - lycopene and vitamin C\nMashed potato\nBanarasi masala\nSev topping\nSweet chutney"],
    4432 => [330, 7, 2, 70, 0, ['gluten_free', 'low_fat'], "Chenna - milk protein\nLight sugar syrup"],
    3936 => [0, 3, 2, 7, 0, ['low_calorie', 'low_fat', 'low_sugar', 'gluten_free'], "Buttermilk - probiotic, aids digestion\nRoasted cumin\nBlack salt"],
    3916 => [0, 4, 5, 37, 1, ['gluten_free'], "Basmati rice\nCumin seeds\nGhee"],
    3933 => [0, 4, 10, 21, 5, ['jain', 'high_fibre', 'gluten_free'], "Seasonal vegetables - fibre and vitamins\nTomato\nNo onion, no garlic\nHing & cumin"],
    3920 => [0, 4, 0.5, 10, 2, ['vegan', 'gluten_free', 'low_calorie'], "Urad dal - roasted, not fried\nBlack pepper"],
    3913 => [0, 15, 22, 12, 4, ['high_protein', 'low_carb', 'gluten_free'], "Spinach - rich in iron and fibre\nPaneer - protein and calcium\nGarlic & ginger\nA little cream"],
    4134 => [0, 7, 3, 24, 6, ['high_fibre', 'vegan', 'gluten_free', 'low_fat', 'low_calorie'], "Boiled chickpeas - plant protein and fibre\nOnion & tomato\nLemon\nChaat masala"],
    3932 => [0, 11, 6, 28, 6, ['jain', 'high_fibre', 'gluten_free'], "Toor dal - plant protein and fibre\nGhee & cumin tempering\nNo onion, no garlic"],
    3921 => [0, 6, 5, 8, 0, ['gluten_free', 'low_calorie'], "Fresh curd - probiotic and protein"],
    3912 => [0, 16, 22, 20, 5, ['high_protein', 'gluten_free'], "Paneer - protein and calcium\nGreen peas - plant protein and fibre\nOnion-tomato gravy"],
    3937 => [0, 7, 8, 38, 0, ['gluten_free'], "Churned curd - probiotic\nSugar\nMalai"],
    3915 => [0, 4, 0.5, 40, 1, ['vegan', 'gluten_free', 'low_fat', 'jain'], "Steamed basmati rice"],
    3927 => [0, 4, 12, 39, 1, [], "Sweet of the day - ask us what is fresh today"],
    4172 => [0, 6, 9, 19, 0, ['gluten_free'], "Milk froth - set overnight in the winter dew\nSaffron\nCardamom\nPistachio"],
    4135 => [0, 5, 15, 31, 3, ['vegan'], "Spiced potato & peas\nFlaky pastry\nChutney"],
    3919 => [0, 0, 3, 1, 0.5, ['vegan', 'gluten_free'], "Mango, lemon & chilli\nMustard oil\nPickling spices"],
    3910 => [0, 5, 8, 27, 5, ['vegan', 'gluten_free', 'high_fibre'], "Potato\nGreen peas - plant protein and fibre\nTomato gravy"],
    3938 => [0, 8, 9, 17, 0, ['gluten_free', 'low_sugar'], "Churned curd - probiotic and protein\nRoasted cumin\nBlack salt"],
    4148 => [0, 8, 10, 40, 0, ['gluten_free'], "Thick curd - probiotic\nMalai\nSugar\nDry fruits"],
    4136 => [0, 7, 16, 35, 3, ['vegan'], "Spiced urad dal filling\nFlaky pastry\nAloo sabzi & chutney"],
    3909 => [0, 4, 9, 21, 5, ['vegan', 'gluten_free', 'high_fibre'], "Bhindi (okra) - fibre\nPotato\nOnion\nDry spices"],
    4137 => [0, 8, 15, 39, 3, [], "Bread slices\nSpiced potato filling\nGram flour batter\nGreen chutney"],
    4173 => [0, 8, 10, 42, 0, ['gluten_free'], "Chilled curd - probiotic\nSaffron (kesar)\nSugar\nMalai"],
    3908 => [0, 5, 9, 23, 5, ['vegan', 'gluten_free', 'high_fibre'], "Cauliflower - fibre and vitamin C\nPotato\nGinger, turmeric & cumin"],
    4150 => [0, 7, 9, 28, 1, ['gluten_free'], "Chilled milk\nAlmonds & cashews\nFennel, cardamom & black pepper\nRose & saffron"],
    3905 => [0, 10, 10, 38, 9, ['vegan', 'gluten_free', 'high_fibre'], "Chickpeas - plant protein and fibre\nPotato\nOnion-tomato gravy"],
    4139 => [0, 4, 11, 31, 3, ['vegan'], "Potato patties\nChole topping\nTamarind & mint chutney"],
    4149 => [0, 3, 3, 13, 0, ['low_calorie', 'gluten_free'], "Tea leaves\nMilk\nGinger (adrak) & cardamom (elaichi)\nServed in a clay kulhad"],
    3906 => [0, 5, 18, 35, 4, ['gluten_free'], "Baby potatoes\nCurd-based spiced gravy\nKashmiri chilli"],
    4140 => [0, 4, 5, 25, 3, ['gluten_free', 'low_calorie'], "Sweet corn - fibre\nButter\nLemon\nChaat masala"],
    3904 => [0, 12, 10, 42, 11, ['high_fibre', 'vegan', 'gluten_free'], "Chickpeas - plant protein and 11 g fibre\nOnion-tomato masala\nWhole spices"],
    4141 => [0, 14, 19, 16, 2, ['high_protein', 'gluten_free'], "Paneer - protein and calcium\nGram flour batter\nAjwain"],
    3903 => [0, 12, 9, 40, 11, ['high_fibre', 'vegan', 'gluten_free'], "Rajma - plant protein and 11 g fibre\nOnion-tomato gravy\nWhole spices"],
    4142 => [0, 3, 11, 28, 3, ['vegan', 'gluten_free'], "Potato\nSalt & peri-peri"],
    3907 => [0, 5, 11, 23, 5, ['vegan', 'gluten_free', 'high_fibre'], "Seasonal vegetables - fibre and vitamins\nOnion-tomato curry"],
    4143 => [0, 6, 6, 33, 3, [], "Bread\nCucumber, tomato & onion\nGreen chutney\nButter"],
    3914 => [0, 22, 18, 20, 4, ['high_protein'], "Soya chaap - 22 g plant protein\nSpicy onion-tomato gravy\nCream"],
    4144 => [0, 12, 15, 34, 2, [], "Bread\nCheese - extra protein\nMild spices\nButter"],
    3902 => [0, 11, 8, 31, 7, ['high_fibre', 'gluten_free'], "Toor & masoor dal - plant protein and fibre\nOnion & tomato\nGhee"],
    4145 => [0, 6, 11, 39, 3, [], "Instant noodles\nMixed vegetables\nSpices"],
    3901 => [0, 11, 7, 28, 7, ['high_fibre', 'gluten_free'], "Yellow dal - plant protein and fibre\nGhee tadka\nCumin, garlic & red chilli"],
    4146 => [0, 6, 12, 39, 2, [], "Instant noodles\nExtra masala\nOnion & tomato"],
    4169 => [0, 5, 10, 27, 3, [], "Tomato\nMashed potato\nBanarasi masala\nSev\nSweet chutney"],
    4170 => [0, 8, 6, 31, 3, ['gluten_free'], "Urad dal bhalla - plant protein\nSweet curd - probiotic\nTamarind & mint chutney"],
    4171 => [0, 6, 12, 27, 4, ['gluten_free'], "Spinach leaves - iron\nGram flour batter\nCurd\nTamarind & mint chutney"],
    3922 => [0, 3, 0.5, 16, 2, ['vegan', 'low_fat', 'low_calorie'], "Whole wheat - fibre, no oil"],
    3923 => [0, 3, 4, 16, 2, ['low_calorie'], "Whole wheat\nFresh butter"],
    3924 => [0, 5, 14, 36, 3, ['vegan'], "Wheat flour\nDeep fried, puffed"],
    3925 => [0, 7, 16, 42, 4, ['vegan'], "Wheat flour\nSpiced dal stuffing\nDeep fried"],
    3928 => [0, 20, 15, 108, 13, ['high_protein', 'high_fibre'], "Dal - plant protein and fibre\n4 rotis\nRice\nSalad & pickle"],
    3929 => [0, 23, 21, 124, 16, ['high_protein', 'high_fibre'], "Veg of the day\nDal - plant protein and fibre\n4 rotis\nRice\nSalad & pickle"],
    3930 => [0, 35, 33, 128, 14, ['high_protein', 'high_fibre'], "Paneer - protein and calcium\nDal\n4 rotis\nJeera rice\nSalad, pickle & papad"],
    3931 => [0, 32, 44, 144, 15, ['high_protein', 'high_fibre'], "Palak paneer - protein and iron\nKhasta poori\nAloo fry\nDal & rice\nSalad, pickle, papad & sweet"],
];
// plan id => [description, photo from dish id]
const TOB_PLANS = [
    2 => ['Home-style veg lunch every day for 28 days: a seasonal sabzi or paneer, dal, rotis, rice, salad and pickle. About 650–780 kcal and 20 g+ protein a meal. The menu changes daily — see This Week\'s Menu below.', 3929],
    3 => ['Fresh veg dinner every night for 28 days: paneer, chole, rajma or soya chaap with dal, rotis, rice, salad and pickle. 20 g+ protein a meal, cooked fresh the same evening.', 3930],
    1 => ['Lunch and dinner for the whole month (56 meals): ghar jaisa khana twice a day with dal, sabzi or paneer, rotis, rice, salad and pickle. The easiest way to eat healthy without cooking.', 3931],
];

$conn = getConnection();
if (empty($_SESSION['tob_once_csrf'])) $_SESSION['tob_once_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['tob_once_csrf'];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$st = $conn->prepare('SELECT restaurant_name FROM users WHERE restaurant_id = ?');
$st->execute([TOB_RID]);
$name = $st->fetchColumn();
if (!$name) exit('Restaurant ' . TOB_RID . ' not found.');
if (!ensureMenuNutritionColumns($conn) || !ensureMenuIngredientsColumn($conn)) exit('Could not add the nutrition columns.');

$apply = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($apply && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) exit('Bad token, reload the page.');

$done = ['nutrition' => 0, 'ingredients' => 0, 'calories' => 0, 'plan_text' => 0, 'plan_photo' => 0, 'skipped' => []];
$get = $conn->prepare('SELECT id, item_name_en, calories, protein_g, fat_g, carbs_g, fibre_g, health_tags, ingredients FROM menu_items WHERE id = ? AND restaurant_id = ?');
if ($apply) $conn->beginTransaction();
try {
    foreach (TOB_DISHES as $id => [$kcal, $p, $f, $c, $fi, $tags, $ingr]) {
        $get->execute([$id, TOB_RID]);
        $row = $get->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $done['skipped'][] = "dish $id (not found)"; continue; }
        if ($row['protein_g'] === null && $row['fat_g'] === null && $row['carbs_g'] === null && $row['fibre_g'] === null && empty($row['health_tags'])) {
            $tags = array_values(array_intersect(array_keys(MENU_HEALTH_TAGS), $tags));
            if ($apply) $conn->prepare('UPDATE menu_items SET protein_g = ?, fat_g = ?, carbs_g = ?, fibre_g = ?, health_tags = ? WHERE id = ? AND restaurant_id = ?')
                ->execute([$p ?: null, $f ?: null, $c ?: null, $fi ?: null, $tags ? implode(',', $tags) : null, $id, TOB_RID]);
            $done['nutrition']++;
        }
        if (trim((string)$row['ingredients']) === '') {
            if ($apply) $conn->prepare('UPDATE menu_items SET ingredients = ? WHERE id = ? AND restaurant_id = ?')->execute([cleanIngredients($ingr), $id, TOB_RID]);
            $done['ingredients']++;
        }
        if ($kcal && (int)$row['calories'] === 0) {
            if ($apply) $conn->prepare('UPDATE menu_items SET calories = ? WHERE id = ? AND restaurant_id = ?')->execute([$kcal, $id, TOB_RID]);
            $done['calories']++;
        }
    }
    $plan = $conn->prepare('SELECT id, description, image_data IS NOT NULL AS has_img FROM meal_plans WHERE id = ? AND restaurant_id = ?');
    $img = $conn->prepare('SELECT image_data, image_mime_type FROM menu_items WHERE id = ? AND restaurant_id = ? AND image_data IS NOT NULL');
    foreach (TOB_PLANS as $pid => [$desc, $dish]) {
        $plan->execute([$pid, TOB_RID]);
        $row = $plan->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $done['skipped'][] = "plan $pid (not found)"; continue; }
        if (trim((string)$row['description']) === '') {
            if ($apply) $conn->prepare('UPDATE meal_plans SET description = ? WHERE id = ? AND restaurant_id = ?')->execute([$desc, $pid, TOB_RID]);
            $done['plan_text']++;
        }
        if (!$row['has_img']) {
            $img->execute([$dish, TOB_RID]);
            if ($photo = $img->fetch(PDO::FETCH_ASSOC)) {
                if ($apply) $conn->prepare('UPDATE meal_plans SET image_data = ?, image_mime_type = ? WHERE id = ? AND restaurant_id = ?')
                    ->execute([$photo['image_data'], $photo['image_mime_type'] ?: 'image/jpeg', $pid, TOB_RID]);
                $done['plan_photo']++;
            } else {
                $done['skipped'][] = "plan $pid photo (dish $dish has no photo)";
            }
        }
    }
    if ($apply) $conn->commit();
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    exit('Error, nothing changed: ' . $h($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Taste of Banaras nutrition</title>
<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 16px;color:#111}li{margin:4px 0}button{padding:12px 20px;border:0;border-radius:10px;background:#1b5e3d;color:#fff;font-weight:700;cursor:pointer}.ok{color:#15803d;font-weight:700}</style></head>
<body>
<h1><?php echo $h($name); ?> (<?php echo TOB_RID; ?>)</h1>
<p class="<?php echo $apply ? 'ok' : ''; ?>"><?php echo $apply ? 'Done. Saved:' : 'This will fill (only empty fields):'; ?></p>
<ul>
<li>Nutrition (protein, fat, carbs, fibre, tags): <b id="n"><?php echo $done['nutrition']; ?></b> dishes</li>
<li>Ingredients: <b id="i"><?php echo $done['ingredients']; ?></b> dishes</li>
<li>Calories (were 0): <b id="k"><?php echo $done['calories']; ?></b> dishes</li>
<li>Plan descriptions: <b id="pt"><?php echo $done['plan_text']; ?></b>, plan photos: <b id="pp"><?php echo $done['plan_photo']; ?></b></li>
<?php foreach ($done['skipped'] as $s): ?><li>Skipped: <?php echo $h($s); ?></li><?php endforeach; ?>
</ul>
<?php if (!$apply): ?>
<form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><button id="apply" type="submit">Apply to live</button></form>
<?php endif; ?>
</body></html>
