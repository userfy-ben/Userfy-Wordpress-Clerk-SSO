<<<<<<< HEAD
# Userfy-Wordpress-Clerk-SSO
Overrides the standard login to use Clerk SSO
=======
# UF_User - Clerk.com SSO for WordPress

A WordPress plugin that replaces the standard login and authentication flow with [Clerk.com](https://clerk.com) for a seamless Single Sign-On (SSO) experience.

## Description

This plugin overrides the default `wp-login.php` page, redirecting users to a secure, Clerk-hosted login form. Upon successful authentication with Clerk, a corresponding user is created or updated in WordPress, and they are logged in. The entire process is designed to be secure and streamlined, removing the need for users to manage a separate WordPress password.

API keys and sensitive configuration are handled via environment variables for enhanced security, following modern development best practices.

## Features

*   **Clerk SSO Integration**: Replaces the standard WordPress login with Clerk.
*   **Automatic User Sync**: Creates and updates WordPress users based on their Clerk profile.
*   **Secure Configuration**: Uses a `.env` file to manage sensitive API keys, keeping them out of the database and version control.
*   **Virtual Pages**: Automatically handles login and logout URLs (`/uf_sso_login`, `/uf_sso_logout`) without needing you to create pages in WordPress.
*   **Admin Settings**: A simple settings page to enable/disable the SSO functionality and configure the post-login redirect page.
*   **Developer Shortcode**: Includes a `[uf_sso_details]` shortcode to display the logged-in user's raw data from Clerk for debugging purposes.

## Requirements

*   PHP 8.0 or higher
*   WordPress 5.0 or higher
*   Composer for dependency management
*   A Clerk.com account with a configured application

## Installation

1.  **Clone the Repository**
    Clone this repository into your WordPress site's `wp-content/plugins/` directory.
    ```shell
    git clone <your-repo-url> uf-user
    ```

2.  **Install Dependencies**
    Navigate to the plugin's directory in your terminal and run Composer to install the required libraries (like `firebase/php-jwt`).
    ```shell
    cd /path/to/wp-content/plugins/uf-user
    composer install
    ```

3.  **Create Environment File**
    Create a `.env` file in the root of the plugin directory (`/wp-content/plugins/uf-user/.env`). You can copy the provided `.env.example` file if one exists.

4.  **Configure API Keys**
    Add your Clerk.com application keys to the `.env` file.
    ```ini
    CLERK_FRONTEND_API="https://clerk.your-domain.com"
    CLERK_PUBLISHABLE_KEY="pk_test_..."
    CLERK_SECRET_KEY="sk_test_..."
    ```
    > **Important**: Add `.env` to your project's `.gitignore` file to prevent committing your secret keys to version control.

5.  **Activate the Plugin**
    In your WordPress admin dashboard, go to "Plugins" and activate the **UF_User** plugin.

## Configuration

Once the plugin is activated, you can configure it from the WordPress admin dashboard.

1.  Navigate to **Userfy > Single Sign-On**.
2.  **Enable Clerk SSO**: Check this box to activate the login page redirect. If this is unchecked, the standard WordPress login form will be used.
3.  **Log In Redirect Page**: Choose a page where users should be sent after they successfully log in. If left blank, they will be redirected to the homepage.

## Usage

### Login and Logout

With the "Enable Clerk SSO" setting active, the plugin automatically handles the login and logout flows:
*   Navigating to `wp-login.php` will redirect to the virtual login page at `/uf_sso_login`.
*   Clicking any standard WordPress logout link will redirect to the virtual logout page at `/uf_sso_logout`, which clears the Clerk session before logging the user out of WordPress.

### Details Shortcode

To display the raw Clerk user data for the currently logged-in user, you can place the following shortcode on any page or post:

```
[uf_sso_details]
```

This is useful for debugging and verifying the data being synced from Clerk.

## License

This plugin is licensed under the GPL-2.0-or-later. See the `uf-user.php` file for more details.
>>>>>>> f219641 (Initial load)
