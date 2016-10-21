# WP Multisite Media Library Sync
Keeps the media library in sync across a muiltsite but doesn't copy the files.

#### How

 * Changes the upload directory to remove the 'sites/x' therefore each site shares the same directory.
 * Attaches to the Add/Edit/Delete attachment events so they are synced to each sites post tables.
 * Attaches to the Add/Update/Delete post meta events so they are synced to each sites postmeta tables.