# Google User Content Importer (GUCI)

## Description

Google User Content Importer (GUCI) is a WordPress plugin that scans posts for Google User Content images, reports metadata, and allows importing these images into your WordPress media library. This plugin is particularly useful for sites that have embedded Google-hosted images and want to bring those assets under their own control.

## Features

- Scans posts for Google User Content images
- Reports image metadata (URL, file type, size, etc.)
- Allows importing individual images or all images from a post
- Updates post content with new image URLs after import
- Detects and prevents duplicate imports using perceptual hashing
- Batch processing for efficient scanning of large sites

## Installation

1. Download the plugin zip file
2. Log in to your WordPress admin panel
3. Go to Plugins > Add New
4. Click on the "Upload Plugin" button
5. Upload the zip file and click "Install Now"
6. After installation, click "Activate Plugin"

## Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher
- GD library enabled on your server

## Usage

1. After activation, you'll find a new menu item "GUCI" in your WordPress admin panel
2. Click on "GUCI" to access the plugin's main page
3. Click "Scan Posts" to start scanning your site for Google User Content images
4. Once the scan is complete, you'll see a list of posts containing Google-hosted images
5. You can choose to import individual images or all images from a specific post
6. After import, the plugin will automatically update your post content with the new image URLs

## Frequently Asked Questions

**Q: Will this plugin affect my existing images?**
A: No, this plugin only affects Google User Content images found in your posts.

**Q: What happens if an image already exists in my media library?**
A: The plugin uses perceptual hashing to detect duplicate images. If an identical image is found, it will use the existing image instead of creating a duplicate.

**Q: Can I customize the filename of imported images?**
A: Yes, you can specify custom filenames for each image before importing.

## Support

If you encounter any issues or have questions, please open an issue on the GitHub repository.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This plugin is licensed under the GPL v2 or later.
