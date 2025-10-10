# Laravel WebGIS User Guide

Complete guide for using the Laravel WebGIS application.

## Table of Contents

1. [Getting Started](#getting-started)
2. [Authentication](#authentication)
3. [Dashboard Overview](#dashboard-overview)
4. [Layer Management](#layer-management)
5. [Map Builder](#map-builder)
6. [Spatial Analysis](#spatial-analysis)
7. [Data Import](#data-import)
8. [Project Management](#project-management)
9. [Organization Settings](#organization-settings)
10. [Tips and Best Practices](#tips-and-best-practices)

## Getting Started

### System Requirements

- Modern web browser (Chrome, Firefox, Safari, Edge)
- Internet connection
- Valid user account

### First Login

1. Navigate to the application URL
2. Click "Login" or "Register"
3. Enter your credentials
4. You'll be redirected to the dashboard

### User Roles

- **Admin**: Full access to all features and settings
- **Editor**: Can create, edit, and publish layers and maps
- **Viewer**: Read-only access to published content

## Authentication

### Registration

1. Click "Register" on the homepage
2. Fill in required information:
   - Name
   - Email
   - Password
   - Organization (optional)
3. Click "Register"
4. Check your email for verification (if enabled)

### Password Reset

1. Click "Forgot Password"
2. Enter your email address
3. Check your email for reset link
4. Follow the link and set a new password

## Dashboard Overview

The dashboard provides:

- **Quick Stats**: Number of layers, maps, and projects
- **Recent Activity**: Latest updates and changes
- **Quick Actions**: Create new layers, maps, or imports
- **System Status**: Health check indicators

### Navigation Menu

- **Dashboard**: Main overview
- **Layers**: Manage spatial layers
- **Maps**: Create and view maps
- **Projects**: Organize work by projects
- **Imports**: Upload spatial data
- **Organization**: Settings and users (admin only)

## Layer Management

### Creating a Layer

#### Method 1: From Data Import

1. Go to **Imports** → **New Import**
2. Upload your spatial data file
3. Wait for processing to complete
4. Layer is automatically created

#### Method 2: From Database Table

1. Go to **Layers** → **Create Layer**
2. Fill in layer details:
   - Name
   - Description
   - Table name (from database)
   - Geometry type
3. Click "Create"

### Layer Properties

- **Name**: Display name for the layer
- **Description**: Detailed description
- **Table Name**: Database table storing the data
- **Geometry Type**: Point, LineString, Polygon, etc.
- **SRID**: Spatial Reference System (default: 4326)
- **Published**: Whether layer is published to GeoServer

### Publishing Layers

1. Navigate to layer details
2. Click "Publish to GeoServer"
3. Wait for publishing to complete
4. Layer is now available via WMS/WFS

### Styling Layers

1. Open layer details
2. Click "Edit Style"
3. Modify SLD (Styled Layer Descriptor):
   - Colors
   - Line widths
   - Fill patterns
   - Labels
4. Click "Save Style"
5. Style is applied to published layer

### Viewing Layer Data

1. Click on a layer
2. Click "View Attributes"
3. See all feature attributes in table format
4. Filter and sort data
5. Click on features to see details

## Map Builder

### Creating a New Map

1. Go to **Maps** → **New Map**
2. Enter map name and description
3. Click "Create" to open Map Builder

### Map Builder Interface

- **Left Panel**: Layer list and tools
- **Center**: Interactive map view
- **Right Panel**: Layer properties and styling
- **Top Bar**: Save, share, and export options

### Adding Layers

#### WMS Layer (from GeoServer)

1. Click "Add Layer"
2. Select "WMS"
3. Choose from published layers
4. Configure visibility and opacity

#### Vector Layer (from file)

1. Click "Add Layer"
2. Select "Vector"
3. Upload GeoJSON file
4. Style the layer

#### Base Maps

1. Click "Base Maps"
2. Choose from:
   - OpenStreetMap
   - Bing Maps
   - Satellite imagery

### Layer Controls

- **Visibility**: Toggle layer on/off
- **Opacity**: Adjust transparency (0-100%)
- **Z-Index**: Change layer order (drag and drop)
- **Style**: Modify layer appearance

### Map Tools

#### Navigation

- **Pan**: Click and drag
- **Zoom**: Mouse wheel or +/- buttons
- **Zoom to Extent**: Fit all layers in view
- **Zoom to Layer**: Focus on specific layer

#### Drawing Tools

1. Click drawing tool (Point, Line, Polygon)
2. Click on map to draw
3. Double-click to finish
4. Right-click to cancel

#### Measurement Tools

1. Click "Measure"
2. Select measurement type:
   - Distance
   - Area
   - Coordinates
3. Click on map to measure
4. Results shown in panel

### Saving Maps

1. Click "Save" button
2. Map configuration is saved
3. View URL is updated
4. Can be shared or embedded

### Sharing Maps

1. Click "Share" button
2. Copy share link
3. Share with others
4. Recipients can view (no login required)

## Spatial Analysis

### Buffer Analysis

Create a buffer zone around features:

1. Open Analysis Panel
2. Select "Buffer"
3. Choose layer or draw geometry
4. Set buffer distance
5. Choose unit (meters, km, miles)
6. Click "Analyze"
7. View results on map

### Spatial Queries

Find features based on spatial relationships:

1. Select "Spatial Query"
2. Choose layer to query
3. Draw or select reference geometry
4. Choose operation:
   - **Within**: Features inside reference
   - **Contains**: Features containing reference
   - **Intersects**: Features crossing reference
   - **Touches**: Features touching reference
5. View results

### Attribute Queries

Filter features by attributes:

1. Select "Attribute Query"
2. Choose layer
3. Add conditions:
   - Field name
   - Operator (=, >, <, LIKE)
   - Value
4. Combine with AND/OR
5. Execute query
6. View filtered results

### Distance Calculations

1. Click "Measure Distance"
2. Click start point
3. Click end point (or multiple points)
4. Distance shown in chosen unit

### Area Calculations

1. Click "Measure Area"
2. Draw polygon
3. Double-click to finish
4. Area shown in chosen unit

## Data Import

### Supported Formats

- **Shapefile** (.zip): Must include .shp, .shx, .dbf
- **GeoJSON** (.geojson, .json): Native JSON format
- **KML/KMZ** (.kml, .kmz): Google Earth format
- **CSV** (.csv): With coordinate columns

### Import Process

1. Go to **Imports** → **Upload File**
2. Drag and drop file or click to browse
3. Fill in import details:
   - Name
   - Description (optional)
4. Click "Upload"
5. Monitor progress:
   - File validation
   - Data processing
   - Table creation
6. Once complete, layer is created

### CSV Import Requirements

CSV files must include:
- Coordinate columns (latitude/longitude OR x/y)
- Column headers in first row
- Valid coordinate values

Example:
```csv
name,latitude,longitude,population
San Francisco,37.7749,-122.4194,884363
Los Angeles,34.0522,-118.2437,3979576
```

### Import Status

- **Pending**: Waiting to process
- **Processing**: Currently importing
- **Completed**: Successfully imported
- **Failed**: Error occurred (check logs)

### Troubleshooting Imports

**File Format Error**:
- Verify file format is supported
- Check file is not corrupted
- Ensure all required files are included (Shapefile)

**Coordinate Error**:
- Check coordinate format (decimal degrees)
- Verify SRID matches data
- Ensure coordinates are valid

**Size Limit Error**:
- File exceeds maximum size (100MB default)
- Split large files into smaller chunks
- Contact admin to increase limit

## Project Management

### Creating Projects

1. Go to **Projects** → **New Project**
2. Enter project details:
   - Name
   - Description
   - Bounding box (optional)
3. Click "Create"

### Project Features

- Organize layers and maps by project
- Invite collaborators
- Track activities and changes
- Add comments and notes

### Collaborating

1. Open project
2. Click "Invite"
3. Enter collaborator email
4. Assign role (admin/editor/viewer)
5. Send invitation

### Project Timeline

View all project activities:
- Layer additions
- Map updates
- Comments
- Member changes

## Organization Settings

### Viewing Settings (Admin Only)

1. Click **Organization** in menu
2. View organization details:
   - Name
   - Description
   - Member count
   - Location

### Managing Users (Admin Only)

1. Go to **Users**
2. View all organization members
3. Edit user roles
4. Deactivate users if needed

### Organization Isolation

- Each organization's data is isolated
- Users only see their organization's content
- Admins manage organization settings

## Tips and Best Practices

### Performance

- Limit features displayed on map (use filters)
- Use appropriate zoom levels
- Simplify complex geometries if needed
- Cache frequently accessed layers

### Data Organization

- Use consistent naming conventions
- Add detailed descriptions to layers
- Organize related layers in projects
- Tag layers for easy searching

### Map Design

- Choose appropriate base maps
- Use color schemes that contrast
- Limit number of visible layers
- Add legends and labels

### Collaboration

- Share maps instead of screenshots
- Use projects for team organization
- Comment on layers for feedback
- Regularly update project status

### Data Quality

- Validate data before import
- Check coordinate systems match
- Remove duplicate features
- Update metadata regularly

### Security

- Use strong passwords
- Don't share account credentials
- Log out on shared computers
- Report suspicious activity

## Keyboard Shortcuts

- **Ctrl+S**: Save current map
- **Ctrl+Z**: Undo last action
- **Ctrl+Y**: Redo action
- **Esc**: Cancel current operation
- **+/-**: Zoom in/out
- **Arrow Keys**: Pan map

## Getting Help

### Documentation

- API Documentation
- Deployment Guide
- Feature-specific guides

### Support

- Check FAQ section
- Search existing issues
- Contact system administrator
- Open GitHub issue

## Appendix

### Glossary

- **WKT**: Well-Known Text format for geometries
- **GeoJSON**: JSON format for geographic data
- **WMS**: Web Map Service
- **WFS**: Web Feature Service
- **SRID**: Spatial Reference System Identifier
- **PostGIS**: Spatial database extension
- **GeoServer**: Map server for publishing layers

### Common SRIDs

- **4326**: WGS 84 (GPS coordinates)
- **3857**: Web Mercator (web maps)
- **2163**: US National Atlas Equal Area

### Resources

- [PostGIS Documentation](https://postgis.net/documentation/)
- [GeoServer Manual](https://docs.geoserver.org/)
- [OpenLayers Documentation](https://openlayers.org/doc/)
