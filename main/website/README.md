# Restaurant Menu Website

A modern, McDonald's-style online menu website for your restaurant with real-time product fetching from the database.

## Features

- 🍔 **Menu Display** - Show all available menu items with categories
- 🔍 **Search Functionality** - Search items by name, description, or category
- 🛒 **Shopping Cart** - Add items to cart with quantity controls
- 📱 **Responsive Design** - Works on desktop, tablet, and mobile
- 🎨 **Modern UI** - McDonald's inspired red and yellow color scheme
- 💾 **Local Storage** - Cart persists between page refreshes

## Files Structure

```
website/
├── index.php          # Main page
├── api.php            # API endpoints for fetching data
├── db_config.php      # Database configuration
├── image.php          # Image serving script
├── style.css          # Styling
├── script.js          # JavaScript functionality
└── README.md          # This file
```

## Setup Instructions

1. **Database Setup**
   - Make sure your database is running (restro2)
   - The database should have the required tables:
     - `menu`
     - `menu_items`
   - Sample data is already included in `database_schema.sql`

2. **Access the Website**
   - Open your browser
   - Navigate to: `http://localhost/menu/website/`
   - The website will load all products from the database

3. **Features to Use**
   - **Category Filter**: Click on menu categories to filter items
   - **Type Filter**: Filter by Veg, Non Veg, Egg, Drink
   - **Category Filter**: Filter by food category
   - **Search**: Type in the search box to find specific items
   - **Add to Cart**: Click "Add" button on any item
   - **Shopping Cart**: Click the cart icon to view and manage items
   - **Checkout**: Click "Checkout" button to place order

## Color Scheme

- **Primary Red**: `#F70000` (McDonald's red)
- **Primary Yellow**: `#FFD100` (McDonald's yellow)
- **Dark Red**: `#DA020E`
- **White Background**: Clean, modern look

## How It Works

1. **Data Loading**
   - `api.php` fetches data from database
   - `loadMenuItems()` loads items based on filters
   - Data is cached in browser for better performance

2. **Shopping Cart**
   - Items stored in `localStorage`
   - Persists between page refreshes
   - Real-time price calculation

3. **Image Loading**
   - Images served through `image.php` for security
   - Falls back to emoji if no image available
   - Cache headers for performance

## Customization

### Change Restaurant Name
Edit line 15 in `index.php`:
```html
<h1>Your Restaurant Name</h1>
```

### Modify Colors
Edit CSS variables in `style.css`:
```css
:root {
    --primary-red: #F70000;
    --primary-yellow: #FFD100;
}
```

### Add More Filters
Edit `api.php` to add custom filters and modify `script.js` to handle them.

## Browser Support

- ✅ Chrome
- ✅ Firefox
- ✅ Safari
- ✅ Edge
- ✅ Mobile browsers

## Troubleshooting

### Images Not Loading
- Check if images exist in `uploads/` folder
- Verify database has `item_image` column populated
- Check file permissions

### Database Connection Error
- Verify database credentials in `db_config.php`
- Check XAMPP MySQL is running
- Ensure database `restro2` exists

### Cart Not Working
- Check browser console for JavaScript errors
- Ensure `localStorage` is enabled in browser
- Try clearing browser cache

## Future Enhancements

- Payment gateway integration
- Order placement to database
- User accounts and profiles
- Order history
- Email/SMS notifications
- Real-time order tracking
- Multi-language support

## Contact & Support

For issues or questions, check the main project documentation or contact the development team.

