# Operations Dashboard

## Overview
The Operations Dashboard is a comprehensive, real-time analytics and monitoring interface for CaminhoIT operations staff. It provides live insights into support tickets, orders, invoices, and user metrics.

## Features

### 📊 Live Data Integration
- **Support Tickets**: Real-time tracking of ticket counts, statuses, and priorities
- **Orders**: Monitor order volumes, completion rates, and processing status
- **Revenue**: Track paid order revenue with detailed breakdowns
- **Invoices**: View invoice statuses including paid, pending, and overdue
- **Users**: Monitor total users and new user growth

### 📈 Interactive Visualizations
1. **Ticket Trend Chart**: Line chart showing daily ticket creation patterns
2. **Order Revenue Chart**: Bar chart displaying daily revenue from paid orders
3. **Ticket Status Distribution**: Doughnut chart showing ticket status breakdown
4. **Top Categories**: Horizontal bar chart highlighting busiest support categories

### 🎨 Design & UX
- **Modern Glassmorphism Design**: Clean, professional interface with subtle blur effects
- **Dark Mode Support**: Toggle between light and dark themes with persistent preference
- **Responsive Layout**: Fully responsive grid system that adapts to all screen sizes
- **Interactive Elements**: Hover effects, smooth transitions, and animations
- **Period Filtering**: View data for Today, 7 Days, 30 Days, 90 Days, or Year

### ⚡ Real-Time Features
- **Auto-Refresh**: Dashboard automatically refreshes every 5 minutes
- **Manual Refresh**: Quick refresh button for instant data updates
- **Recent Activity**: Live feed of the 5 most recent support tickets
- **Dynamic Stats**: All metrics update based on selected time period

## Typography
- **Headings**: Space Grotesk font family (as per CaminhoIT design standards)
- **Body Text**: Inter font family for optimal readability

## Access Control
The dashboard is restricted to authenticated users with the following roles:
- Administrator
- Staff
- Support User
- Support Technician
- Accountant

## Navigation
The dashboard includes a comprehensive navbar with links to:
- Main Dashboard
- Support Tickets & Analytics
- Orders, Quotes & Invoices
- User Account

## Technical Implementation

### Database Queries
All queries use prepared statements for security and include proper error handling with fallback values. The dashboard queries:
- `support_tickets` table
- `orders` table
- `invoices` table
- `users` table
- `support_ticket_groups` table

### Libraries Used
- **Bootstrap 5**: Responsive layout and navbar components
- **Chart.js**: Interactive data visualizations
- **Lucide Icons**: Modern icon set for UI elements
- **Google Fonts**: Space Grotesk and Inter font families

### Dark Mode
Dark mode is implemented using CSS custom properties (CSS variables) that switch between light and dark color schemes. The preference is stored in localStorage for persistence across sessions.

### Performance
- Efficient SQL queries with aggregations
- Client-side chart rendering
- Minimal external dependencies
- Optimized asset loading

## Color Palette

### Light Mode
- Background: #F8FAFC
- Card Background: #ffffff
- Primary: #4F46E5 (Indigo)
- Success: #10B981 (Green)
- Warning: #F59E0B (Amber)
- Danger: #EF4444 (Red)
- Info: #06B6D4 (Cyan)

### Dark Mode
- Background: #0f172a
- Card Background: #1e293b
- (Colors maintain same accent palette)

## File Location
`/operations/dashboard.php`

## URL
Access the dashboard at: `https://caminhoit.com/GIT/operations/dashboard.php`

## Future Enhancements
Potential improvements for future iterations:
- Export dashboard data to PDF/Excel
- Custom date range selector
- Email alerts for critical metrics
- Drill-down capability on charts
- Real-time WebSocket updates
- Performance comparison periods
- Customizable dashboard widgets
- User activity heatmap
- SLA tracking metrics
- Team performance leaderboard

## Support
For issues or questions about the dashboard, contact the CaminhoIT development team.
