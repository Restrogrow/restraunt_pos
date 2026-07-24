<?php
// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Include authorization configuration
require_once __DIR__ . '/../config/authorization_config.php';

// Require admin permission to update menu items
requireAdmin();

require_once '../db_connection.php';

$restaurant_id = $_SESSION['restaurant_id'];

try {
    $conn = getConnection();
    
    // First, delete all existing menu items for this restaurant
    $deleteStmt = $conn->prepare("DELETE FROM menu_items WHERE restaurant_id = ?");
    $deleteStmt->execute([$restaurant_id]);
    
    echo "Old menu items deleted.<br>";
    
    // Now insert the real menu items
    $realMenuItems = [
        // Breakfast Menu (menu_id = 1)
        ['RES001', 1, 'Egg Fried Rice', 'Fried rice with scrambled eggs, vegetables and spices', 'Main Course', 'Egg', 12, 320, TRUE, 180.00, FALSE],
        ['RES001', 1, 'Masala Omelette', 'Two egg omelette with onions, tomatoes and spices', 'Main Course', 'Egg', 8, 220, TRUE, 150.00, FALSE],
        ['RES001', 1, 'Poha', 'Flattened rice cooked with onions, curry leaves and peanuts', 'Main Course', 'Veg', 10, 180, TRUE, 80.00, FALSE],
        ['RES001', 1, 'Paratha with Butter', 'Crispy layered flatbread served with butter', 'Main Course', 'Veg', 8, 350, TRUE, 100.00, TRUE],
        ['RES001', 1, 'Bread Toast with Jam', 'Toasted bread slices with butter and jam', 'Main Course', 'Veg', 5, 180, TRUE, 60.00, FALSE],
        ['RES001', 1, 'Egg Bhurji', 'Spiced scrambled eggs with onions and tomatoes', 'Main Course', 'Egg', 8, 240, TRUE, 140.00, FALSE],
        ['RES001', 1, 'Aloo Paratha', 'Stuffed wheat flatbread with spiced potatoes', 'Main Course', 'Veg', 10, 280, TRUE, 120.00, FALSE],
        ['RES001', 1, 'Suji Upma', 'Semolina cooked with vegetables and spices', 'Main Course', 'Veg', 10, 200, TRUE, 90.00, FALSE],
        
        // Lunch Menu (menu_id = 2)
        ['RES001', 2, 'Butter Chicken', 'Creamy tomato based curry with tender chicken pieces', 'Main Course', 'Non Veg', 20, 450, TRUE, 320.00, FALSE],
        ['RES001', 2, 'Dal Makhani', 'Slow cooked black lentils with cream and butter', 'Main Course', 'Veg', 15, 300, TRUE, 180.00, FALSE],
        ['RES001', 2, 'Chicken Biryani', 'Fragrant basmati rice with marinated chicken and spices', 'Main Course', 'Non Veg', 25, 550, TRUE, 280.00, FALSE],
        ['RES001', 2, 'Veg Biryani', 'Aromatic basmati rice with mixed vegetables and spices', 'Main Course', 'Veg', 20, 400, TRUE, 200.00, FALSE],
        ['RES001', 2, 'Paneer Tikka', 'Grilled cottage cheese cubes marinated in spices', 'Appetizer', 'Veg', 15, 280, TRUE, 220.00, FALSE],
        ['RES001', 2, 'Chicken Tikka', 'Grilled chicken pieces marinated in yogurt and spices', 'Appetizer', 'Non Veg', 18, 300, TRUE, 280.00, FALSE],
        ['RES001', 2, 'Naan', 'Soft leavened flatbread baked in tandoor', 'Bread', 'Veg', 8, 180, TRUE, 50.00, TRUE],
        ['RES001', 2, 'Roti', 'Freshly made whole wheat flatbread', 'Bread', 'Veg', 5, 120, TRUE, 30.00, FALSE],
        ['RES001', 2, 'Jeera Rice', 'Basmati rice tempered with cumin seeds', 'Rice', 'Veg', 10, 200, TRUE, 100.00, FALSE],
        ['RES001', 2, 'Gobi Manchurian', 'Crispy cauliflower in sweet and spicy sauce', 'Appetizer', 'Veg', 15, 250, TRUE, 200.00, FALSE],
        ['RES001', 2, 'Chicken Curry', 'Traditional chicken curry with onions and tomatoes', 'Main Course', 'Non Veg', 20, 380, TRUE, 260.00, FALSE],
        ['RES001', 2, 'Aloo Gobi', 'Potatoes and cauliflower cooked with Indian spices', 'Main Course', 'Veg', 15, 180, TRUE, 150.00, FALSE],
        ['RES001', 2, 'Rajma Chawal', 'Kidney beans curry served with steamed rice', 'Main Course', 'Veg', 20, 350, TRUE, 170.00, FALSE],
        ['RES001', 2, 'Chole Bhature', 'Spiced chickpeas with deep fried bread', 'Main Course', 'Veg', 15, 450, TRUE, 180.00, FALSE],
        
        // Dinner Menu (menu_id = 3)
        ['RES001', 3, 'Grilled Chicken Breast', 'Marinated chicken breast grilled to perfection', 'Main Course', 'Non Veg', 18, 350, TRUE, 350.00, FALSE],
        ['RES001', 3, 'Mixed Grill Platter', 'Assorted grilled meats and vegetables', 'Main Course', 'Non Veg', 25, 600, TRUE, 450.00, FALSE],
        ['RES001', 3, 'Mutton Rogan Josh', 'Tender mutton cooked in rich Kashmiri curry', 'Main Course', 'Non Veg', 30, 500, TRUE, 380.00, FALSE],
        ['RES001', 3, 'Palak Paneer', 'Cottage cheese cubes in creamy spinach gravy', 'Main Course', 'Veg', 15, 320, TRUE, 220.00, FALSE],
        ['RES001', 3, 'Dal Tadka', 'Yellow lentils tempered with spices and herbs', 'Main Course', 'Veg', 12, 200, TRUE, 140.00, FALSE],
        ['RES001', 3, 'Kadai Paneer', 'Cottage cheese cooked in spicy kadai masala', 'Main Course', 'Veg', 15, 340, TRUE, 240.00, FALSE],
        ['RES001', 3, 'Chicken Laziz', 'Creamy chicken cooked in rich cashew gravy', 'Main Course', 'Non Veg', 20, 420, TRUE, 320.00, FALSE],
        ['RES001', 3, 'Veg Kofta Curry', 'Vegetable dumplings in creamy tomato gravy', 'Main Course', 'Veg', 18, 280, TRUE, 200.00, FALSE],
        ['RES001', 3, 'Mutton Curry', 'Mutton cooked in traditional Indian spices', 'Main Course', 'Non Veg', 30, 480, TRUE, 360.00, FALSE],
        ['RES001', 3, 'Baingan Bharta', 'Smoky roasted eggplant mashed with spices', 'Main Course', 'Veg', 15, 180, TRUE, 160.00, FALSE],
        
        // Snacks Menu (menu_id = 4)
        ['RES001', 4, 'French Fries', 'Crispy golden french fries served hot', 'Snacks', 'Veg', 8, 320, TRUE, 120.00, TRUE],
        ['RES001', 4, 'Onion Rings', 'Crispy fried onion rings with dipping sauce', 'Snacks', 'Veg', 10, 280, TRUE, 150.00, FALSE],
        ['RES001', 4, 'Chicken Wings', 'Spicy fried chicken wings with hot sauce', 'Snacks', 'Non Veg', 15, 380, TRUE, 250.00, TRUE],
        ['RES001', 4, 'Spring Rolls', 'Crispy vegetable spring rolls with dip', 'Snacks', 'Veg', 12, 200, TRUE, 140.00, FALSE],
        ['RES001', 4, 'Mozzarella Sticks', 'Crispy mozzarella cheese sticks with marinara', 'Snacks', 'Veg', 10, 300, TRUE, 180.00, FALSE],
        ['RES001', 4, 'Paneer Pakora', 'Deep fried cottage cheese fritters', 'Snacks', 'Veg', 12, 250, TRUE, 160.00, FALSE],
        ['RES001', 4, 'Chicken Nuggets', 'Crispy breaded chicken nuggets', 'Snacks', 'Non Veg', 12, 350, TRUE, 220.00, TRUE],
        ['RES001', 4, 'Aloo Tikki', 'Spiced potato patties served with chutneys', 'Snacks', 'Veg', 10, 180, TRUE, 120.00, FALSE],
        ['RES001', 4, 'Samosa', 'Crispy pastry filled with spiced potatoes', 'Snacks', 'Veg', 8, 150, TRUE, 40.00, FALSE],
        ['RES001', 4, 'Vada Pav', 'Spiced potato fritter in soft bread bun', 'Snacks', 'Veg', 8, 220, TRUE, 50.00, FALSE],
        
        // Beverages Menu (menu_id = 5)
        ['RES001', 5, 'Fresh Orange Juice', 'Freshly squeezed orange juice', 'Beverages', 'Drink', 3, 110, TRUE, 100.00, FALSE],
        ['RES001', 5, 'Mango Lassi', 'Sweet mango yogurt drink', 'Beverages', 'Drink', 5, 180, TRUE, 120.00, FALSE],
        ['RES001', 5, 'Masala Chai', 'Spiced tea with milk and herbs', 'Beverages', 'Drink', 5, 60, TRUE, 40.00, FALSE],
        ['RES001', 5, 'Coffee', 'Hot brewed coffee', 'Beverages', 'Drink', 5, 5, TRUE, 60.00, TRUE],
        ['RES001', 5, 'Cold Coffee', 'Chilled coffee with ice cream', 'Beverages', 'Drink', 5, 250, TRUE, 100.00, FALSE],
        ['RES001', 5, 'Lemonade', 'Fresh lemon juice with sugar and soda', 'Beverages', 'Drink', 3, 120, TRUE, 80.00, FALSE],
        ['RES001', 5, 'Buttermilk', 'Spiced yogurt drink', 'Beverages', 'Drink', 3, 50, TRUE, 50.00, FALSE],
        ['RES001', 5, 'Ice Tea', 'Chilled tea with lemon', 'Beverages', 'Drink', 3, 90, TRUE, 70.00, FALSE],
        ['RES001', 5, 'Badam Milk', 'Sweet almond milk', 'Beverages', 'Drink', 5, 180, TRUE, 120.00, FALSE],
        ['RES001', 5, 'Water Bottle', 'Packaged drinking water', 'Beverages', 'Drink', 1, 0, TRUE, 30.00, FALSE],
        ['RES001', 5, 'Soft Drinks', 'Coca Cola, Pepsi, Sprite (250ml)', 'Beverages', 'Drink', 2, 140, TRUE, 50.00, TRUE],
        ['RES001', 5, 'Fresh Lime Soda', 'Lime juice with soda water', 'Beverages', 'Drink', 3, 80, TRUE, 60.00, FALSE],
    ];
    
    $stmt = $conn->prepare("INSERT INTO menu_items (restaurant_id, menu_id, item_name_en, item_description_en, item_category, item_type, preparation_time, calories, is_available, base_price, has_variations) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $count = 0;
    foreach ($realMenuItems as $item) {
        $stmt->execute($item);
        $count++;
    }
    
    echo "Successfully inserted $count real menu items!<br>";
    echo "<br><a href='dashboard.php'>Go to Dashboard</a> | <a href='update_menu_items.php'>Refresh Page</a>";
    
} catch (Exception $e) {
    error_log("Error in update_menu_items.php: " . $e->getMessage());
    echo 'An error occurred. Please try again.';
}

