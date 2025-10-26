# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

BDC IMS (Bharat Datacenter Infrastructure Management System) is a vanilla JavaScript frontend application for managing datacenter hardware inventory and server configurations. This is a pure frontend application with no build process, serving static HTML/CSS/JS files that communicate with a remote PHP API.

**Key characteristics:**
- Pure vanilla JavaScript (no frameworks/bundlers)
- Multi-page application with separate concerns per page
- Uses `FormData` for all API requests
- JWT-based authentication with localStorage
- Bootstrap/Font Awesome for UI

## Development Setup

**No build process or development server required.**

This application consists entirely of static HTML, CSS, and JavaScript files. Simply open [index.html](index.html) in a browser or use any static file server (e.g., VS Code Live Server, Python's `http.server`, etc.).

The `package.json` includes optional `http-server` scripts for convenience, but they are not required for development.

## Tech Stack

- **HTML5** - Semantic markup for all pages
- **CSS3** - Custom styles with CSS animations and transitions
- **Vanilla JavaScript (ES6+)** - No frameworks (async/await, classes, modules via script tags)
- **Font Awesome 6** - Icon library (loaded via CDN)
- **Bootstrap CSS** - Used selectively in server pages for grid/utilities
- **Axios** - HTTP client (loaded via CDN, used only in server pages)
- **Fetch API** - Native browser API used in dashboard pages

## Color Theme

The application uses a neutral and relaxing color palette defined in [server/server-theme.css](server/server-theme.css):

**Primary Colors:**
- Primary: `#9BA9B2` (neutral gray-blue)
- Primary Dark: `#8899a3` (darker shade)
- Secondary: `#BFC7CA` (light gray-blue)

**Status Colors:**
- Success: `#8fbc8f` (soft green)
- Warning: `#d4a574` (soft orange)
- Danger: `#c88888` (soft red)
- Info: `#89b4c9` (soft blue)

**Backgrounds:**
- Background: `#F6F6F6` (light gray)
- Surface/White: `#FFFFFF` (white)

**Text:**
- Primary Text: `#2E2E2E` (dark gray)
- Secondary Text: `#6B6B6B` (medium gray)
- Muted Text: `#8E8E8E` (light gray)

**Borders:**
- Border: `#DCDCDC` (light gray)

All colors are defined as CSS custom properties (`:root` variables) for consistent theming across the application.

## Project Structure & File Purposes

```
/
├── index.html              # Entry point: Login/registration page with form toggle
├── script.js               # Authentication logic (login, register, token management)
├── styles.css              # Styles for auth pages (login/register UI, animations)
│
├── dashboard/
│   ├── index.html          # Main inventory dashboard with sidebar navigation
│   ├── css/
│   │   └── dashboard.css   # Dashboard-specific styles (sidebar, cards, tables)
│   └── js/
│       ├── dashboard.js    # Core dashboard: component CRUD, search, filtering, pagination
│       ├── api.js          # API abstraction layer (fetch-based) with auto token refresh
│       ├── utils.js        # Utilities: alerts, date formatting, field validation
│       └── add-server-form.js  # Modal for creating new server configurations
│
├── server/
│   ├── server-list.js      # Displays list of server configurations, handles creation modal
│   ├── server-api.js       # Server-specific API calls (axios-based class)
│   ├── server-builder.css  # Styles for server configuration builder interface
│   └── server-theme.css    # Additional theming for server pages
│
├── forms/
│   ├── add-component.html  # Standalone page for adding hardware components
│   ├── edit-component.html # Standalone page for editing existing components
│   ├── css/
│   │   └── forms.css       # Form-specific styling (inputs, buttons, validation states)
│   └── js/
│       ├── add-form.js     # Dynamic form builder: fetches fields by component type, handles submission
│       └── edit-form.js    # Similar to add-form but pre-populates existing component data
│
├── css/
│   └── toast.css          # Styles for toast notification system
├── js/
│   └── toast.js           # Toast notification implementation (success/error/info messages)
│
└── All-JSON/              # Reference JSON schemas for component types (CPU, RAM, etc.)
```

## API Integration

**Base URL:** `https://shubham.staging.cloudmate.in/bdc_ims/api/api.php`

**API Documentation:** See [bdc-ims-api-complete.md](bdc-ims-api-complete.md) for complete endpoint documentation.

**Request format:**
- All requests use `FormData` (not JSON)
- Action-based routing: `action` parameter determines endpoint
- Protected endpoints require `Authorization: Bearer <token>` header

**Authentication flow:**
1. Login via `auth-login` action → receive JWT access token + refresh token
2. Store tokens in localStorage (`bdc_token`, `bdc_refresh_token`)
3. Include access token in subsequent requests
4. Auto-refresh on 401 using refresh token (handled in [dashboard/js/api.js](dashboard/js/api.js))

**Token storage keys:**
- `bdc_token` - JWT access token (primary)
- `bdc_refresh_token` - Refresh token
- `bdc_user` - User object (JSON string)
- Note: Some code uses legacy `jwt_token` for backward compatibility

## Architecture Patterns

### API Layer Abstraction

Two API abstraction patterns exist:

1. **Dashboard API ([dashboard/js/api.js](dashboard/js/api.js))**
   - Uses native `fetch` API
   - Centralized token management with auto-refresh
   - Returns parsed JSON responses
   - Example:
     ```javascript
     await api.request('component-list', { type: 'cpu', limit: 10 });
     ```

2. **Server API ([server/server-api.js](server/server-api.js))**
   - Uses `axios` library
   - Class-based with methods per endpoint
   - Example:
     ```javascript
     const serverApi = new ServerAPI();
     await serverApi.getServerConfigs(20, 0);
     ```

### Component Management

The system manages hardware components with a hierarchical type system.

**Currently implemented component types:**
- **CPU** - Processors with family/model hierarchies
- **RAM** - Memory modules with capacity and speed specifications
- **Storage** - Hard drives, SSDs with capacity and interface types
- **Motherboard** - Server motherboards with socket and chipset info
- **NIC** (Network Interface Card) - Network adapters with speed/port specs
- **Caddy** - Drive caddies/trays for hot-swap bays
- **Chassis** - Server chassis/cases with form factor specs
- **PCIe Card** - PCIe expansion cards (GPU, RAID, etc.)
- **Servers** - Complete server configurations (assembled from components)

**Planned but not yet implemented:**
- **HBA Card** (Host Bus Adapter) - Storage controller cards for SAS/SATA connectivity

**Component operations:**
- List: `component-list` action with filters (type, status, search)
- Create: `component-add` action with type-specific fields
- Update: `component-update` action
- Delete: `component-delete` action
- Search: `component-search` action with global search across types

**Component statuses:**
- Available (1) - Ready for use
- In Use (2) - Currently assigned to a server
- Failed (3) - Defective/non-functional
- Reserved (4) - Allocated but not yet deployed

### Dynamic Form Generation

Forms ([forms/js/add-form.js](forms/js/add-form.js), [forms/js/edit-form.js](forms/js/edit-form.js)) are generated dynamically based on component type. Each component type has different fields fetched from the API.

**Form flow:**
1. User selects component type from dropdown
2. Fetch type-specific fields via `component-get-fields` action
3. Dynamically render form inputs based on field definitions
4. Submit with all field values via FormData

### Server Configuration Builder

The server builder allows creating server configurations by assembling components:
1. Create initial config via `server-create-start`
2. Select base component (Motherboard/Chassis) via `server-select-base`
3. Add components via `server-add-component`
4. Finalize configuration via `server-finalize`

Each server config has a UUID and tracks components through assembly stages.

## Common Development Tasks

### Adding a New Component Type

1. Ensure backend API supports the component type
2. Update dashboard sidebar menu in [dashboard/index.html](dashboard/index.html)
3. Add count tracking in [dashboard/js/dashboard.js](dashboard/js/dashboard.js)
4. Verify form generation handles the new type's fields

### Debugging Authentication Issues

1. Check localStorage tokens in browser DevTools
2. Verify token expiration (default 3600s)
3. Check console for auto-refresh attempts
4. Ensure `Authorization` header is present in requests

### Testing API Changes

1. Update base URL in API files if testing against different endpoint
2. Check [bdc-ims-api-complete.md](bdc-ims-api-complete.md) for request/response formats
3. Monitor browser Network tab for FormData structure
4. All responses follow standard format: `{ success, message, data, code }`

## Important Notes

- No TypeScript, no bundler, no package-based dependencies (except axios loaded via CDN in some pages)
- Page navigation is via `window.location.href` (hard redirects, no SPA routing)
- Authentication checks happen on each page load via localStorage
- Logout clears all localStorage auth keys and redirects to index.html
- CSS is split by concern: global styles, dashboard styles, form styles, server-builder styles
- Bootstrap classes are used in server-related pages, custom CSS elsewhere
- All date formatting uses [dashboard/js/utils.js](dashboard/js/utils.js) utilities
