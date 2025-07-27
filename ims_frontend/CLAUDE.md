# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is the frontend directory for the BDC Inventory Management System (IMS). Currently, this directory contains only API documentation and is prepared for frontend development.

## Current State

- **No frontend code exists yet** - this is a fresh setup ready for development
- **API Documentation**: Complete backend API documentation is available in `bdc-ims-api-complete.md`
- **Backend Base URL**: `https://shubham.staging.cloudmate.in/bdc_ims/api/api.php`

## API Integration Details

The backend uses:
- **Authentication**: JWT tokens via Bearer authentication
- **Content Types**: `application/x-www-form-urlencoded` or `application/json`
- **Permission System**: Role-based access control with granular permissions (e.g., `cpu.create`, `cpu.edit`)

## Available API Modules

- Authentication (login/logout)
- Dashboard (inventory summaries)
- Component Management (CPU, Memory, Storage, NIC, Motherboard, PSU, GPU, Server, Cabinet)
- Search functionality
- User Management
- Role & Permission Management
- Access Control Lists (ACL)

## Development Setup Commands

Since no package.json exists yet, future developers should:

1. **Initialize the project**:
   ```bash
   npm init -y
   # or
   yarn init -y
   ```

2. **Install frontend framework** (recommendation based on API complexity):
   ```bash
   # For React
   npx create-react-app . --template typescript
   # or for Next.js
   npx create-next-app@latest . --typescript
   # or for Vue
   npm create vue@latest .
   ```

3. **Install additional dependencies for API integration**:
   ```bash
   npm install axios @types/node
   # or
   yarn add axios @types/node
   ```

## Architecture Recommendations

Given the complex permission system and component management:

1. **State Management**: Consider Redux Toolkit or Zustand for complex permission states
2. **API Layer**: Create dedicated services for each component type (CPU, Memory, etc.)
3. **Authentication**: Implement JWT token management with auto-refresh
4. **Permission Guards**: Create route/component guards based on user permissions
5. **Component Structure**: Mirror the backend component types in the frontend architecture

## Key Integration Points

- **Permission Checks**: Every action should validate against user permissions
- **Component CRUD**: Each component type has its own endpoints and required fields
- **Search**: Unified search across all component types
- **Dashboard**: Real-time inventory status displays
- **User Management**: Admin interface for role and permission assignment

## Development Notes

- The backend requires specific form-data formatting for most endpoints
- All timestamps are in MySQL datetime format
- Component serial numbers must be unique across the system
- Status codes: 0=Failed, 1=Available, 2=In Use
- UUIDs are auto-generated for all components