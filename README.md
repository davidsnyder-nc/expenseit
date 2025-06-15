# expense.it - AI-Powered Trip Expense Tracker

A modern, single-user web application for tracking travel expenses with AI-powered receipt processing and mobile camera integration.

![expense.it Logo](assets/logo.png)

## Features

### 🚀 Core Functionality
- **AI-Powered Receipt Processing** - Automatic expense extraction using Google Gemini Vision API
- **Mobile Camera Capture** - Take photos of receipts directly on mobile devices
- **Smart File Organization** - Automatic categorization and storage of receipts and documents
- **Trip Management** - Create, view, edit, and archive complete trips
- **PDF Report Generation** - Professional expense reports with itemized breakdowns

### 📱 Mobile-First Design
- **Responsive Interface** - Optimized for both desktop and mobile devices
- **Camera Integration** - Native camera access for instant receipt capture
- **Touch-Friendly Controls** - Large buttons and intuitive navigation
- **Offline-Ready** - Works without constant internet connection

### 🎯 Smart Features
- **Automatic Categorization** - AI categorizes expenses (meals, transportation, lodging, etc.)
- **Date Recognition** - Extracts dates from receipts automatically
- **Merchant Detection** - Identifies business names and locations
- **Tax Tracking** - Separate tracking for tax-deductible expenses
- **Archive System** - Keep historical trips organized

## Technology Stack

- **Frontend**: HTML5, CSS3, JavaScript (ES6+)
- **Backend**: PHP 8.1+
- **AI Processing**: Google Gemini Vision API
- **PDF Generation**: mPDF library
- **File Storage**: Flat file system (no database required)
- **Icons**: Feather Icons
- **Mobile**: Progressive Web App features

## Quick Start

### Prerequisites
- PHP 8.1 or higher
- Composer
- Web server (Apache/Nginx)
- Google Gemini API key

### Installation
```bash
# Clone repository
git clone https://github.com/yourusername/expense-tracker.git
cd expense-tracker

# Install dependencies
composer install

# Set up environment
cp .env.example .env
# Add your GEMINI_API_KEY to .env

# Set permissions
chmod 755 data/ data/trips/ data/archive/
```

For detailed installation instructions, see [INSTALL.md](INSTALL.md).

### Usage
1. Open the application in your browser
2. Click "Create New Trip" to start
3. Upload receipts or use mobile camera to capture them
4. Review AI-processed expenses and make adjustments
5. Generate PDF reports for expense reimbursement

## Screenshots

### Desktop Interface
- Clean, modern dashboard with trip overview
- Drag-and-drop file upload with progress indicators
- Detailed expense editing with category selection

### Mobile Experience
- Full-screen camera interface for receipt capture
- Touch-optimized navigation and controls
- Responsive design adapts to all screen sizes

## File Structure

```
expense-tracker/
├── assets/
│   ├── style.css          # Main stylesheet
│   ├── wizard.js          # Trip creation wizard
│   └── logo.png           # Application logo
├── data/
│   ├── trips/             # Active trip data
│   └── archive/           # Archived trips
├── vendor/                # Composer dependencies
├── api.php                # Main API endpoint
├── gemini.php             # AI processing logic
├── upload.php             # File upload handler
├── generate_pdf.php       # PDF report generator
├── index.html             # Main dashboard
├── trip.html              # Trip detail view
├── wizard.html            # Trip creation wizard
└── composer.json          # PHP dependencies
```

## API Integration

### Google Gemini Vision API
The application uses Google's Gemini Vision API to process receipt images and extract:
- Merchant names and addresses
- Transaction dates and times
- Item descriptions and prices
- Tax amounts and totals
- Payment methods

### Supported File Formats
- **Images**: JPEG, PNG, HEIC, TIFF, WebP, BMP, GIF
- **Documents**: PDF

## Configuration

### Environment Variables
```bash
GEMINI_API_KEY=your_gemini_api_key_here
APP_ENV=production
DEBUG=false
```

### PHP Settings
```ini
upload_max_filesize = 10M
post_max_size = 10M
max_execution_time = 60
```

## Development

### Local Development
```bash
# Start PHP development server
php -S localhost:8000

# Watch for file changes (optional)
composer run dev
```

### Code Style
- PSR-12 coding standards for PHP
- ES6+ for JavaScript
- Mobile-first CSS approach
- Semantic HTML structure

## Security

### Best Practices
- Environment variables for sensitive data
- Input validation and sanitization
- Secure file upload handling
- HTTPS enforcement in production
- Regular dependency updates

### Data Privacy
- All data stored locally on your server
- No third-party data sharing
- API keys encrypted in environment files
- Optional data encryption at rest

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/new-feature`)
3. Commit changes (`git commit -am 'Add new feature'`)
4. Push to branch (`git push origin feature/new-feature`)
5. Create Pull Request

### Development Guidelines
- Write clear, documented code
- Add tests for new features
- Follow existing code style
- Update documentation as needed

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

### Getting Help
- Check the [Installation Guide](INSTALL.md) for setup issues
- Review the troubleshooting section for common problems
- Open an issue for bugs or feature requests

### Reporting Issues
When reporting issues, please include:
- Operating system and PHP version
- Error messages and logs
- Steps to reproduce the problem
- Screenshots if applicable

## Acknowledgments

- **Google Gemini AI** - For powerful receipt processing capabilities
- **mPDF** - For PDF generation functionality
- **Feather Icons** - For beautiful, consistent icons
- **PHP Community** - For excellent documentation and libraries

## Roadmap

### Upcoming Features
- **Multi-user Support** - Team expense tracking
- **Cloud Storage Integration** - Backup to Google Drive/Dropbox
- **Advanced Analytics** - Spending patterns and insights
- **Mobile App** - Native iOS/Android applications
- **API Webhooks** - Integration with accounting software

### Version History
- **v1.0.0** - Initial release with core functionality
- **v1.1.0** - Added mobile camera capture
- **v1.2.0** - Improved AI accuracy and PDF reports

---

Made with ❤️ for travelers who want to simplify expense tracking.