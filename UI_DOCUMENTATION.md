# Data Import System - User Interface Documentation

## Navigation

The data import feature is accessible from the main navigation bar for authenticated users with an organization:

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Laravel GIS                                                            │
│  ┌────────────┬──────────────┬──────────────┬──────────────┐           │
│  │ Dashboard  │ Data Imports │ Organization │ Users (Admin)│           │
│  └────────────┴──────────────┴──────────────┴──────────────┘           │
└─────────────────────────────────────────────────────────────────────────┘
```

## 1. Import List View (`/imports`)

The main index page showing all imports for the current user:

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Data Imports                                        [+ New Import]      │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  File Name      Type    Size    Status      Progress  Features  Actions │
│  ──────────────────────────────────────────────────────────────────────  │
│  cities.geojson GEOJSON 24 KB   ✓Completed  ████ 100% 5         View    │
│  counties.shp   SHAPEFILE 1.2MB ⟳Processing ██░░  50% -         View    │
│  points.kml     KML     156 KB  ⚠Failed     ░░░░   0% -         View    │
│  data.csv       CSV     89 KB   ⏱Pending    ░░░░   0% -         View    │
│                                                                          │
│                                           ← 1 2 3 ... 10 →              │
└─────────────────────────────────────────────────────────────────────────┘
```

### Features:
- Paginated list of all imports
- Status badges with colors (green=completed, blue=processing, red=failed, gray=pending)
- Progress bars showing completion percentage
- Feature count displayed after import completes
- Quick actions: View details, Delete (for completed/failed)
- Empty state with call-to-action for first import

## 2. File Upload Form (`/imports/create`)

Drag-and-drop upload interface:

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Upload Spatial Data File                                    [Cancel]   │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  Supported File Formats                                                 │
│  ┌────────┬────────┬────────┬────────┐                                 │
│  │   📍   │   💚   │   📌   │   📊   │                                 │
│  │Shapefile│GeoJSON │  KML   │  CSV   │                                 │
│  │.shp+   │.geojson│ .kml   │ .csv   │                                 │
│  └────────┴────────┴────────┴────────┘                                 │
│                                                                          │
│  Main File *                                                            │
│  ┌───────────────────────────────────────────────────────────┐         │
│  │                                                             │         │
│  │                    ☁️ [cloud icon]                         │         │
│  │                                                             │         │
│  │              Drag & Drop your file here                    │         │
│  │                                                             │         │
│  │                        or                                  │         │
│  │                  [Browse Files]                            │         │
│  │                                                             │         │
│  └───────────────────────────────────────────────────────────┘         │
│                                                                          │
│  Additional Files (for Shapefiles)                                      │
│  [Choose Files: .shx, .dbf, .prj, .cpg]                                │
│                                                                          │
│  ℹ️ Note: Large files will be processed in the background.              │
│  You can monitor the progress on the imports page.                      │
│                                                                          │
│                                              [Upload File]              │
└─────────────────────────────────────────────────────────────────────────┘
```

### Features:
- Visual file format guide with icons
- Large drag-and-drop zone
- Hover effects and drag-over highlighting
- Automatic file info display after selection
- Conditional additional files section for Shapefiles
- Informative note about background processing
- Client-side file extension detection

## 3. Import Detail View (`/imports/{id}`)

Detailed view with real-time progress updates:

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Import Details                              [Back to Imports]          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ✓ Import Completed Successfully!                                       │
│                                                                          │
│  File Information                   Import Results                      │
│  ┌───────────────────────────┐    ┌───────────────────────────────┐   │
│  │ File Name:  cities.geojson │    │ Table Name:                    │   │
│  │ File Type:  GEOJSON        │    │ import_org1_cities_abc123def   │   │
│  │ File Size:  24.5 KB        │    │                                │   │
│  │ Status:     ✓ Completed    │    │ Geometry Type: Point           │   │
│  │ Uploaded By: John Doe      │    │ Feature Count: 5               │   │
│  │ Uploaded At:                │    │ Started At: 2025-01-08 10:30  │   │
│  │   2025-01-08 10:30:15      │    │ Completed At: 2025-01-08 10:31│   │
│  └───────────────────────────┘    │ Duration: 45 seconds           │   │
│                                    └───────────────────────────────┘   │
│                                                                          │
│  Additional Metadata                                                    │
│  ┌────────────────────────────────────────────────────────────────┐   │
│  │ {                                                               │   │
│  │   "geometry_type": "Point",                                    │   │
│  │   "feature_count": 5,                                          │   │
│  │   "srid": 4326,                                                │   │
│  │   "bbox": "-122.5, 37.7, -117.2, 38.6"                        │   │
│  │ }                                                               │   │
│  └────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│  ℹ️ Next Steps:                                                         │
│  • The data is now available in PostGIS table: import_org1_cities...   │
│  • You can publish this layer to GeoServer for visualization           │
│  • The layer can be queried using SQL or displayed on maps              │
│                                                                          │
│                                            [🗑️ Delete Import Record]    │
└─────────────────────────────────────────────────────────────────────────┘
```

### Processing State:

When import is in progress, the view shows:

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Import Details                              [Back to Imports]          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ⟳ Import In Progress... Please wait while your file is being processed│
│                                                                          │
│  Processing Progress                                                    │
│  ████████████████░░░░░░░░░  65%                                        │
│                                                                          │
│  File Information                                                       │
│  ┌───────────────────────────────────────────────────────────────┐    │
│  │ File Name:  counties.shp                                       │    │
│  │ File Type:  SHAPEFILE                                          │    │
│  │ File Size:  1.2 MB                                             │    │
│  │ Status:     ⟳ Processing                                       │    │
│  │ ...                                                            │    │
│  └───────────────────────────────────────────────────────────────┘    │
│                                                                          │
│  [Auto-refreshing every 3 seconds...]                                  │
└─────────────────────────────────────────────────────────────────────────┘
```

### Failed State:

When import fails:

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Import Details                              [Back to Imports]          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ⚠️ Import Failed                                                        │
│  Failed to process file: Invalid geometry detected in feature 15       │
│                                                                          │
│  File Information                                                       │
│  ┌───────────────────────────────────────────────────────────────┐    │
│  │ File Name:  invalid.geojson                                    │    │
│  │ File Type:  GEOJSON                                            │    │
│  │ Status:     ⚠ Failed                                           │    │
│  │ Error: Invalid geometry detected in feature 15                 │    │
│  └───────────────────────────────────────────────────────────────┘    │
│                                                                          │
│                                            [🗑️ Delete Import Record]    │
└─────────────────────────────────────────────────────────────────────────┘
```

## 4. Empty State

When no imports exist:

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Data Imports                                        [+ New Import]      │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│                                                                          │
│                            📁                                            │
│                     No imports yet.                                     │
│                                                                          │
│                  [Upload Your First File]                               │
│                                                                          │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

## 5. Mobile Responsive Design

The interface is fully responsive and adapts to mobile devices:

```
┌──────────────────────────┐
│  Data Imports      ☰     │
├──────────────────────────┤
│  [+ New Import]          │
│                          │
│  cities.geojson          │
│  Type: GEOJSON           │
│  Status: ✓ Completed     │
│  Progress: ████ 100%     │
│  Features: 5             │
│  [View] [Delete]         │
│  ────────────────────    │
│  counties.shp            │
│  Type: SHAPEFILE         │
│  Status: ⟳ Processing    │
│  Progress: ██░░  50%     │
│  [View]                  │
│  ────────────────────    │
│                          │
│  ← 1 2 3 ... →           │
└──────────────────────────┘
```

## Visual Elements

### Status Badges

```
✓ Completed    (Green badge)
⟳ Processing   (Blue badge with animation)
⚠ Failed       (Red badge)
⏱ Pending      (Gray badge)
```

### Progress Bars

```
Completed: ████████████████████ 100%
Processing: ████████████░░░░░░░░  60%
Failed:     ░░░░░░░░░░░░░░░░░░░░   0%
Pending:    ░░░░░░░░░░░░░░░░░░░░   0%
```

### File Type Icons

- 📍 Shapefile (Blue map icon)
- 💚 GeoJSON (Green code icon)
- 📌 KML (Red marker icon)
- 📊 CSV (Yellow table icon)

## Interaction Flow

### Upload Process

```
1. User clicks "New Import"
   ↓
2. Drag file or browse
   ↓
3. File selected → shows file info
   ↓
4. (If Shapefile) Select companion files
   ↓
5. Click "Upload File"
   ↓
6. Redirect to detail page
   ↓
7. View real-time progress
   ↓
8. Import completes → show results
```

### Status Updates

```
AJAX Polling (Every 3 seconds)
   ↓
GET /imports/{id}/status
   ↓
Update: progress, status, table_name, feature_count
   ↓
If status changes → Reload page
```

## Accessibility Features

- ✅ Semantic HTML structure
- ✅ ARIA labels for screen readers
- ✅ Keyboard navigation support
- ✅ Focus indicators
- ✅ Color contrast compliance
- ✅ Alt text for icons
- ✅ Form validation messages

## Browser Support

- ✅ Chrome 90+
- ✅ Firefox 88+
- ✅ Safari 14+
- ✅ Edge 90+
- ✅ Mobile browsers (iOS Safari, Chrome Mobile)

## Key UI Technologies

- **Bootstrap 5** - Responsive grid and components
- **Font Awesome** - Icons
- **JavaScript (Vanilla)** - Drag & drop, AJAX polling
- **Blade Templates** - Server-side rendering
- **CSS3** - Animations and transitions

## User Experience Highlights

1. **Instant Feedback**: Visual confirmation on file selection
2. **Real-time Updates**: Auto-refreshing progress without page reload
3. **Clear Status**: Color-coded badges and progress bars
4. **Error Reporting**: Detailed error messages when imports fail
5. **Mobile-Friendly**: Responsive design works on all devices
6. **Intuitive Navigation**: Clear breadcrumbs and back buttons
7. **Helpful Guidance**: Tooltips and info messages throughout
