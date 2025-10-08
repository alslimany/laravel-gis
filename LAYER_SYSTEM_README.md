# Layer Management System - Quick Start Guide

Welcome to the Laravel GIS Layer Management System! This guide will help you get started quickly.

## 🎯 What Is It?

A comprehensive system for managing geographic layers in your Laravel GIS application. Think of layers as collections of geographic features (points, lines, polygons) that you can view, edit, style, and publish to GeoServer.

## 🚀 Quick Start

### Step 1: Import Some Data

First, import geographic data using the Data Import feature:

1. Go to **Data Imports** → **New Import**
2. Upload a GeoJSON, Shapefile, or KML file
3. Wait for processing to complete
4. Note the table name created (e.g., `import_1234_cities`)

### Step 2: Create a Layer

Now create a layer from your imported data:

1. Go to **Layers** → **New Layer**
2. Fill in the form:
   - **Name**: "California Cities" (or your preferred name)
   - **Description**: "Major cities in California"
   - **Project**: Select a project (optional)
   - **Table Name**: Enter the table name from your import
   - **Geometry Type**: Select the appropriate type (Point, Polygon, etc.)
3. Click **Create Layer**

### Step 3: Customize the Style

Make your layer look great:

1. On the layer details page, click **Edit**
2. Scroll to "Style Configuration"
3. Choose colors and styles:
   - **Fill Color**: The color inside shapes
   - **Stroke Color**: The outline color
   - **Stroke Width**: Line thickness (1-10)
   - **Fill Opacity**: Transparency (0.0-1.0)
4. Click **Update Style**

### Step 4: Publish to GeoServer

Share your layer through GeoServer:

1. On the layer details page, click **Publish to GeoServer**
2. Your layer is now available via WMS/WFS services!
3. Use the GeoServer workspace name to access it externally

### Step 5: View the Data

Explore your layer's attributes:

1. Click **View Attribute Table**
2. Use the search box to find specific features
3. Adjust pagination as needed (10, 25, 50, 100 records)
4. View geometry data in WKT format

## 📋 Common Tasks

### View All Layers
```
Navigate to: /layers
```
See all layers in your organization with their status, feature counts, and quick actions.

### Download as GeoJSON
```
Click: "Download as GeoJSON" button
Or visit: /layers/{id}/geojson
```
Export any layer as GeoJSON for use in other applications.

### Edit Layer Metadata
```
Click: Edit button on layer details page
```
Update name, description, project association, and styles.

### Delete a Layer
```
Click: Delete button (Admin only)
```
Removes the layer and cleans up GeoServer if published.

### Search in Attribute Table
```
1. Go to layer's attribute table
2. Enter search term
3. Results filtered across all columns
```

## 🔐 Permissions

### Who Can Do What?

| Action | Viewer | Editor | Admin |
|--------|--------|--------|-------|
| View layers | ✅ | ✅ | ✅ |
| Create layers | ❌ | ✅ | ✅ |
| Edit layers | ❌ | ✅ | ✅ |
| Delete layers | ❌ | ❌ | ✅ |
| Publish to GeoServer | ❌ | ✅ | ✅ |
| View attribute table | ✅ | ✅ | ✅ |

**Note**: All users can only see layers from their own organization.

## 💡 Tips & Tricks

### 1. Name Layers Clearly
Use descriptive names like "2024_Census_Blocks" instead of "layer1"

### 2. Add Descriptions
Include metadata about data source, date, and purpose

### 3. Link to Projects
Associate layers with projects for better organization

### 4. Test Before Publishing
View the attribute table and check the data before publishing to GeoServer

### 5. Use Appropriate Colors
Choose colors that make sense for your data:
- Green for parks/vegetation
- Blue for water bodies
- Red for important features
- Gray for background/infrastructure

### 6. Adjust Opacity for Overlays
If you have multiple layers, use lower opacity (0.5-0.7) so features don't hide each other

### 7. Regular Cleanup
Delete old or unused layers to keep the system organized

## 🔗 Integration with Other Features

### From Data Imports
The easiest way to create layers:
```php
// After import completes:
1. Note the table_name from DataImport
2. Create a new Layer using that table_name
3. Layer inherits geometry_type and feature_count
```

### With Projects
Organize layers by project:
```php
// Associate layer with project during creation
// View all layers in a project from project details page
```

### With GeoServer
Publish layers for external access:
```php
// After publishing, layer is available via:
// WMS: http://geoserver/workspace/wms
// WFS: http://geoserver/workspace/wfs
```

## 📊 Understanding the Interface

### Layer Index Page
- **Table View**: All layers in your organization
- **Status Badge**: Shows if layer is published
- **Feature Count**: Number of geographic features
- **Actions**: Quick buttons for View/Edit/Delete

### Layer Details Page
Sections:
- **General Information**: Name, description, table name
- **Publishing Status**: GeoServer details
- **Quick Actions**: Common tasks in one place
- **Style Configuration**: Current style settings

### Attribute Table Page
Components:
- **Search Bar**: Find specific features
- **Pagination Controls**: Adjust page size
- **Data Table**: All feature attributes
- **Statistics Panel**: Layer summary

### Edit Page
Tabs:
- **Basic Info**: Name, description, project
- **Style Configuration**: Visual appearance

## 🛠️ Troubleshooting

### Problem: "Layer creation failed"
**Solution**: Verify the PostGIS table exists and you have permission to access it.

### Problem: "Cannot publish to GeoServer"
**Solution**: Check GeoServer connection settings and verify workspace exists.

### Problem: "Attribute table is empty"
**Solution**: Ensure the table contains data and the geometry column is properly configured.

### Problem: "Permission denied"
**Solution**: Check your role (Editor/Admin required for most operations) and organization membership.

### Problem: "Layer not showing in list"
**Solution**: Layers are filtered by organization. Ensure you're viewing the correct organization's layers.

## 📖 Documentation

For more detailed information, see:

- **[LAYER_MANAGEMENT.md](LAYER_MANAGEMENT.md)**: Complete feature documentation with API details
- **[LAYER_IMPLEMENTATION_SUMMARY.md](LAYER_IMPLEMENTATION_SUMMARY.md)**: Technical implementation details
- **[DATA_IMPORT.md](DATA_IMPORT.md)**: Data import system documentation
- **[GEOSERVER_INTEGRATION.md](GEOSERVER_INTEGRATION.md)**: GeoServer integration guide

## 🎨 Style Examples

### Points (Cities, POIs)
```json
{
  "fillColor": "#FF0000",
  "strokeColor": "#000000",
  "strokeWidth": 1,
  "fillOpacity": 0.8,
  "pointRadius": 6
}
```

### Lines (Roads, Rivers)
```json
{
  "strokeColor": "#0000FF",
  "strokeWidth": 2,
  "fillOpacity": 1.0
}
```

### Polygons (Boundaries, Zones)
```json
{
  "fillColor": "#00FF00",
  "strokeColor": "#006600",
  "strokeWidth": 2,
  "fillOpacity": 0.5
}
```

## 🚦 Workflow Example

Here's a complete workflow from import to publish:

```
1. IMPORT DATA
   └─> Upload GeoJSON file
   └─> Wait for processing
   └─> Get table name: "import_1234_parks"

2. CREATE LAYER
   └─> Name: "City Parks"
   └─> Table: "import_1234_parks"
   └─> Geometry: Polygon

3. STYLE LAYER
   └─> Fill: Green (#00FF00)
   └─> Stroke: Dark Green (#006600)
   └─> Opacity: 0.6

4. VERIFY DATA
   └─> View attribute table
   └─> Check feature count
   └─> Search for specific parks

5. PUBLISH
   └─> Click "Publish to GeoServer"
   └─> Layer now available externally

6. SHARE
   └─> Provide WMS URL to team
   └─> Add to web maps
   └─> Generate reports
```

## 📞 Need Help?

- Check the comprehensive documentation files
- Review the test cases in `tests/Feature/LayerTest.php`
- Check Laravel logs for detailed error messages
- Verify your role and organization membership

## ✨ Best Practices

1. **Always add descriptions**: Future you will thank you
2. **Test in development first**: Before publishing to production
3. **Use meaningful names**: Make it easy to find layers later
4. **Regular backups**: Export important layers as GeoJSON
5. **Monitor feature counts**: Large layers may need optimization
6. **Document data sources**: Add metadata about where data came from
7. **Clean up regularly**: Delete unused layers to maintain performance
8. **Check permissions**: Verify team members have appropriate access

## 🎉 You're Ready!

You now know how to:
- ✅ Create and manage layers
- ✅ Style layers with custom colors
- ✅ Publish to GeoServer
- ✅ View and search attribute data
- ✅ Export as GeoJSON
- ✅ Organize with projects

Start by creating your first layer and exploring the features!
