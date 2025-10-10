# WebGIS Fixes - Manual Testing Checklist

## Pre-Testing Setup
- [ ] Ensure database is set up and migrated
- [ ] Create at least one test user with organization
- [ ] Import or create at least one layer with geometry data
- [ ] Clear browser cache and cookies

## Issue 1: Map Save Route 404 Error

### Test Steps:
1. [ ] Log in to the application
2. [ ] Navigate to Maps > Map Builder
3. [ ] Add at least one layer to the map
4. [ ] Pan and zoom the map to a specific location
5. [ ] Click the "Save Map" button in the header
6. [ ] Verify success message appears: "Map saved successfully!"
7. [ ] Navigate to Maps list
8. [ ] Verify the new map appears in the list

### Expected Results:
- ✅ No 404 error
- ✅ Success alert appears
- ✅ Map is saved to database
- ✅ Map appears in Maps index

### Failure Indicators:
- ❌ "Failed to save map" alert
- ❌ Console shows 404 error
- ❌ Network tab shows POST /api/maps returns 404

---

## Issue 2: Drawing Tools Stuck and Empty Properties Popup

### Test Steps:
1. [ ] Open Map Builder
2. [ ] Click the Point tool (map pin icon)
3. [ ] Click on the map to draw a point
4. [ ] Verify properties dialog appears immediately
5. [ ] Click "Cancel" to close dialog
6. [ ] Click the Line tool (minus icon)
7. [ ] Verify Point tool is no longer active (icon not highlighted)
8. [ ] Draw a line by clicking multiple points, double-click to finish
9. [ ] Verify properties dialog appears with labeled fields:
   - [ ] Name field
   - [ ] Description field
   - [ ] Type field
   - [ ] Notes field
10. [ ] Fill in all fields:
    - Name: "Test Feature"
    - Description: "Testing drawing tools"
    - Type: "test"
    - Notes: "Additional test notes"
11. [ ] Click "Save Properties"
12. [ ] Click on the drawn feature
13. [ ] Verify popup shows the saved properties

### Expected Results:
- ✅ Only one tool active at a time
- ✅ Previous tool deactivates when new tool selected
- ✅ Properties dialog has clear labels
- ✅ Properties are saved to feature
- ✅ Properties appear in popup when clicking feature

### Failure Indicators:
- ❌ Multiple tools active simultaneously
- ❌ Empty form fields without labels
- ❌ Properties not saved
- ❌ Dialog doesn't appear after drawing

---

## Issue 3: Base Map Switching Not Working

### Test Steps:
1. [ ] Open Map Builder
2. [ ] Note the current basemap (should be OpenStreetMap by default)
3. [ ] Open Layer Panel on the left
4. [ ] Find "Base Map" dropdown selector
5. [ ] Test each basemap option:
   - [ ] Select "Satellite" - verify aerial imagery appears
   - [ ] Select "Terrain" - verify topographic map appears
   - [ ] Select "Light" - verify light gray basemap appears
   - [ ] Select "Dark" - verify dark basemap appears
   - [ ] Select "OpenStreetMap" - verify standard OSM tiles appear
6. [ ] For each change, verify:
   - [ ] Map tiles update immediately (within 1-2 seconds)
   - [ ] No console errors
   - [ ] Map remains interactive (can pan and zoom)

### Expected Results:
- ✅ Each basemap selection changes the map display
- ✅ Tiles load properly for each basemap
- ✅ No JavaScript errors in console
- ✅ Map remains functional after switching

### Failure Indicators:
- ❌ Basemap doesn't change when selected
- ❌ Only OpenStreetMap displays regardless of selection
- ❌ Console shows tile loading errors
- ❌ Map becomes unresponsive

---

## Issue 4: Attribute Table Route Parameter Exception

### Test Steps:
1. [ ] Navigate to Layers index
2. [ ] Click on any layer to view details
3. [ ] Scroll to "Quick Actions" section
4. [ ] Click "View Attribute Table" button
5. [ ] Verify attribute table page loads without error
6. [ ] Verify table shows:
   - [ ] Column headers
   - [ ] Feature data in rows
   - [ ] Pagination controls
   - [ ] Search box
7. [ ] Test search functionality
8. [ ] Test pagination (if more than 25 records)
9. [ ] Return to layer details
10. [ ] Click "Download as GeoJSON" button
11. [ ] Verify GeoJSON downloads successfully

### Expected Results:
- ✅ Attribute table loads without errors
- ✅ Data displays in table format
- ✅ All features are accessible
- ✅ GeoJSON download works

### Failure Indicators:
- ❌ UrlGenerationException error
- ❌ Message: "Missing required parameter for [Route: layers.attributes]"
- ❌ 404 error
- ❌ Blank page or error page

---

## Issue 5: Layer Style Changes Not Applied

### Test Steps:
1. [ ] Navigate to Layers index
2. [ ] Select a layer with polygon or line geometry
3. [ ] Click "Edit" button
4. [ ] Scroll to "Style Configuration" section
5. [ ] Note current style values
6. [ ] Change the following:
   - [ ] Fill Color: Change to bright red (#FF0000)
   - [ ] Stroke Color: Change to black (#000000)
   - [ ] Stroke Width: Change to 3
   - [ ] Fill Opacity: Change to 0.7
7. [ ] Click "Update Style" button
8. [ ] Verify success message appears
9. [ ] Click "Preview on Map" button (or navigate to Map Builder)
10. [ ] Add the edited layer to the map
11. [ ] Verify layer displays with NEW colors:
    - [ ] Fill is red with 70% opacity
    - [ ] Stroke is black with width 3
12. [ ] If layer is published to GeoServer:
    - [ ] Open GeoServer admin panel
    - [ ] Navigate to Styles
    - [ ] Verify style was updated (if GeoServer integration is configured)

### Expected Results:
- ✅ Style values save successfully
- ✅ Layer displays with new colors on map
- ✅ Colors match the selected values
- ✅ For published layers, GeoServer styles updated

### Failure Indicators:
- ❌ Layer continues showing old/default blue color
- ❌ Style changes don't appear on map
- ❌ Error saving style
- ❌ Colors don't match selected values

---

## Integration Tests

### Complete Workflow Test:
1. [ ] Create a new map
2. [ ] Add a layer with custom style
3. [ ] Draw features with properties
4. [ ] Change basemap
5. [ ] Save the map
6. [ ] Reload the page
7. [ ] Verify all changes persisted

### Cross-Browser Testing:
- [ ] Chrome/Edge
- [ ] Firefox
- [ ] Safari (if available)

---

## Known Limitations

1. **Bing Maps**: Requires valid Bing Maps API key (currently placeholder)
2. **GeoServer Style Sync**: Requires organization with GeoServer connection configured
3. **Feature Properties**: Currently only stored in client-side map, not persisted to database

---

## Reporting Issues

If any test fails, please report:
1. Which test failed (issue number and step)
2. Expected vs actual behavior
3. Browser console errors (if any)
4. Network tab errors (if any)
5. Screenshots showing the issue

---

## Success Criteria

All 5 issues are considered resolved when:
- ✅ Maps can be saved without 404 errors
- ✅ Drawing tools properly deactivate and show labeled property forms
- ✅ All basemap options display correctly
- ✅ Attribute table loads without route parameter errors
- ✅ Style changes appear on map visualization
