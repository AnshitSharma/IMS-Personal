# GEMINI.md

This file provides guidance to Gemini when working with the frontend code in this repository.

## Project Overview

This is the frontend for the BDC Inventory Management System (IMS). It is built with vanilla HTML, CSS, and JavaScript and interacts with a PHP backend API.

## Key Files

- **Login Page:** `index.html` (Handles user login)
- **Login Script:** `script.js` (Handles login logic and API calls)
- **Dashboard Page:** `dashboard/index.html` (Main interface after login)
- **Dashboard Script:** `dashboard/js/dashboard.js` (Handles dashboard functionality, component lists, and interactions)
- **API Handler:** `dashboard/js/api.js` (A centralized handler for all backend API communication)
- **Utilities:** `dashboard/js/utils.js` (Contains helper functions for alerts, date formatting, etc.)
- **Component Forms:** `dashboard/forms/` (HTML, CSS, and JS for adding/editing components)
- **API Documentation:** `bdc-ims-api-complete.md` (Detailed backend API documentation)

## Backend API

- **Base URL:** `https://shubham.staging.cloudmate.in/bdc_ims/api/api.php`
- **Authentication:** JWT (JSON Web Tokens) via Bearer token in the Authorization header.
- **Token Management:** The `api.js` file handles storing and refreshing tokens. See `api.setToken()`, `api.getRefreshToken()`, and `api.refreshToken()`.

## Development Conventions

- **Structure:** The code is organized by feature (dashboard, forms).
- **API Interaction:** All API calls should go through the `window.api` object defined in `dashboard/js/api.js`. This ensures consistent token handling and error management.
- **Utilities:** Use the helper functions in `dashboard/js/utils.js` for common tasks like showing alerts, formatting data, and creating modals.
- **Styling:** Global styles are in `styles.css`. Dashboard-specific styles are in `dashboard/css/dashboard.css`, and form styles are in `dashboard/forms/css/forms.css`.

## How to Work with the Code

### Listing Components
To list components (e.g., CPUs), use the `api.components.list('cpu', params)` function. The `dashboard.js` file contains the logic for fetching and rendering these lists in the table view.

### Adding/Editing Components
The `dashboard/forms/` directory contains the necessary files.
- `add-component.html` and `add-form.js` handle adding new components.
- `edit-component.html` and `edit-form.js` handle editing existing components.
- These forms are typically loaded into a modal in the dashboard.

### Authentication
- The login flow is handled in `index.html` and `script.js`.
- After a successful login, the user is redirected to `dashboard/index.html`.
- The dashboard's JavaScript (`api.js` and `dashboard.js`) handles token verification and refresh.

## Running the Frontend

The frontend can be run by opening the `ims_frontend/index.html` file in a web browser. No build step is currently required. For development, using a simple live server extension in your editor is recommended to avoid CORS issues when making API calls.
