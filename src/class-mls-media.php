<?php

/**
 * Class MLS_Media
 */
class MLS_Media {

    /**
     * Key for meta used to link attachments between sites.
     * @var string
     */
    public static $meta                = '_mls_synced_id';

    /**
     * Array of blacklisted meta keys to avoid syncing.
     * @var array
     */
    public static $metaBlacklistedKeys = array(
        '_mls_synced_id',
        '_edit_lock'
    );

    /**
     * Constructor which calls three sets of hooks:
     * 1) Changing upload directory to remove the 'sites/x'
     * 2) Attach to the Add/Edit/Delete attachment events
     * 3) Attach to the Add/Update/Delete post meta events
     */
    public function __construct()
    {
        $this->set_upload_dir_hooks();
        $this->set_attachment_hooks();
        $this->set_meta_hooks();
    }

    /**
     * Sets up the necessary hooks to alter the upload directory
     */
    protected function set_upload_dir_hooks() {
        add_filter( 'upload_dir', array( $this, 'set_upload_dir' ) );
    }

    /**
     * Sets up the necessary hooks for Add/Edit/Delete attachment events
     */
    protected function set_attachment_hooks() {
        add_action( 'add_attachment',    array( $this, 'add'    ) );
        add_action( 'edit_attachment',   array( $this, 'edit'   ) );
        add_action( 'delete_attachment', array( $this, 'delete' ) );
    }

    /**
     * Removes hooks set in `set_attachment_hooks`.
     * This is useful later for to stop listening while we sync
     * across each site.
     */
    protected function remove_attachment_hooks() {
        remove_action( 'add_attachment',    array( $this, 'add'    ) );
        remove_action( 'edit_attachment',   array( $this, 'edit'   ) );
        remove_action( 'delete_attachment', array( $this, 'delete' ) );
    }

    /**
     * Sets up the necessary hooks for the Add/Update/Delete post meta events
     */
    protected function set_meta_hooks() {
        add_action( 'added_post_meta',  array( $this, 'meta_update' ), 10, 4 );
        add_action( 'update_post_meta', array( $this, 'meta_update' ), 10, 4 );
        add_action( 'delete_post_meta', array( $this, 'meta_delete' ), 10, 4 );
    }

     /**
     * Removes hooks set in `set_meta_hooks`.
     * This is useful later for to stop listening while we sync
     * across each site.
     */
    protected function remove_meta_hooks() {
        remove_action( 'added_post_meta',  array( $this, 'meta_update' ) );
        remove_action( 'update_post_meta', array( $this, 'meta_update' ) );
        remove_action( 'delete_post_meta', array( $this, 'meta_delete' ) );
    }

    /**
     * Strips out the 'site/x' added by Multisite meaning all
     * sites use the same upload directory to ensure we only have one file
     * per image size.
     *
     * @param array $uploads  Array of upload directory data with keys of 'path', 'url', 'subdir, 'basedir', and 'error'.
     */
    public function set_upload_dir( $uploads ) {
        $fields = array( 'path', 'url', 'basedir', 'baseurl' );
        foreach ( $fields as $key ) {
            $uploads[ $key ] = preg_replace( '/\/sites\/\d/i', '', $uploads[ $key ] );
        }
        return $uploads;
    }

    /**
     * Syncs attachment to each site by:
     * 1) Disable hooks listing Add/Edit/Delete attachment
     *     events to avoid infinite.
     * 2) If an Add event it will add synced id in the form of post meta.
     * 3) Get the synced id back from post meta - if done on add event this a
     *     little point but needed for Edit/Delete.
     * 4) Get post (attachment) from database
     * 5) Get all sites id's except the current site
     * 6) Foreach blog site we recarry out the action Add/Edit/Delete
     * 7) Due to '_wp_attached_file' meta being fired before each synced attachment
     *     has its own synced id we need manaully run the sync when its an Add event.
     * 8) Enable hooks listing Add/Edit/Delete attachment events.
     *
     * @param string $action        The action/event type: add|edit|delete
     * @param int    $attachment_id Attachment ID
     */
    public function sync( $action, $attachment_id ) {
        $this->remove_attachment_hooks();

        if ( $action == 'add' ) {
            $this->add_sync_meta( $attachment_id, $attachment_id );
        }

        $synced_id = get_post_meta( $attachment_id, self::$meta, true );
        $media     = get_post( $attachment_id, ARRAY_A );
        $blogs     = $this->get_blog_ids_without_current_site();

        foreach ( $blogs as $blog ) {
            $this->wp_post_action( $action, $media, $blog, $synced_id );
        }

        if ( $action == 'add' ) {
            $this->late_sync_of_attached_file( $attachment_id );
        }
        $this->set_attachment_hooks();
    }

    /**
     * Syncs attachment meta to each site by:
     * 1) If the meta key is blackedlisted then return (no need to sync)
     * 2) If this is not a post with the type of 'attachment' return
     * 3) Disable hooks listing Add/Update/Delete meta events to avoid infinite.
     * 4) Get the synced id back from post meta.
     * 5) Get all sites id's except the current site
     * 6) Foreach blog site we recarry out the action Add/Update/Delete
     * 7) Enable hooks listing Add/Update/Delete meta events.
     *
     * @param string $action     The action/event type: update|delete
     * @param int    $meta_id    ID of the row being added
     * @param int    $object_id  ID of the object the metadata applies to
     * @param string $meta_key   Metadata key
     * @param mixed  $meta_value Metadata value
     */
    public function meta_sync( $action, $meta_id, $object_id, $meta_key, $meta_value ) {

        if ( in_array( $meta_key, self::$metaBlacklistedKeys ) ) {
            return;
        }

        if ( get_post_type( $object_id ) != 'attachment' ) {
            return;
        }

        $this->remove_meta_hooks();

        $synced_id = get_post_meta( $object_id, self::$meta, true );
        $blogs     = $this->get_blog_ids_without_current_site();

        foreach ( $blogs as $blog ) {
            $this->wp_meta_action( $action, $meta_id, $object_id, $meta_key, $meta_value, $blog, $synced_id );
        }

        $this->set_meta_hooks();
    }

    /**
     * Syncs the '_wp_attached_file' by fetching the current value from
     * the current site meta then passing it to 'meta_sync' to be synced.
     *
     * @param int $attachment_id Attachment ID
     */
    public function late_sync_of_attached_file( $attachment_id ) {

        $meta_key   = '_wp_attached_file';
        $meta_value = get_post_meta( $attachment_id, $meta_key, true );

        $this->meta_sync( 'update', false, $attachment_id, $meta_key, $meta_value );
    }

    /**
     * Called by 'add_attachment' which in turns calls 'sync' with the action 'add'
     * @param int $attachment_id Attachment ID
     */
    public function add( $attachment_id ) {
        $this->sync( 'add', $attachment_id );
    }

    /**
     * Called by 'edit_attachment' which in turns calls 'sync' with the action 'edit'
     * @param int $attachment_id Attachment ID
     */
    public function edit( $attachment_id ) {
        $this->sync( 'edit', $attachment_id );
    }

    /**
     * Called by 'delete_attachment' which in turns calls 'sync' with the action 'delete'
     * @param int $attachment_id Attachment ID
     */
    public function delete( $attachment_id ) {
        $this->sync( 'delete', $attachment_id );
    }

    /**
     * Called by 'added_post_meta' and 'update_post_meta' which in turns calls 'meta_sync' with the action 'update'
     * @param int    $meta_id    ID of the row being added
     * @param int    $object_id  ID of the object the metadata applies to
     * @param string $meta_key   Metadata key
     * @param mixed  $meta_value Metadata value
     */
    public function meta_update( $meta_id, $object_id, $meta_key, $meta_value ) {
        $this->meta_sync( 'update', $meta_id, $object_id, $meta_key, $meta_value );
    }

    /**
     * Called by 'added_post_meta' which in turns calls 'meta_sync' with the action 'update'
     * @param int    $meta_id    ID of the row being added
     * @param int    $object_id  ID of the object the metadata applies to
     * @param string $meta_key   Metadata key
     * @param mixed  $meta_value Metadata value
     */
    public function meta_delete( $meta_ids, $object_id, $meta_key, $meta_value ) {
        foreach ( $meta_ids as $meta_id ) {
            $this->meta_sync( 'delete', $meta_id, $object_id, $meta_key, $meta_value );
        }
    }

    /**
     * Switches to the site we need to make a change on a post then calls the wrapper
     * functions for wp_insert_post | wp_update_post | wp_delete_post
     *
     * @param string $action    The action/event type: add|edit|delete
     * @param array  $postarr   An array of post data
     * @param int    $blog_id   ID of the site/blog
     * @param int    $synced_id Synced ID
     */
    protected function wp_post_action( $action, $postarr, $blog_id, $synced_id ) {
        switch_to_blog( $blog_id );
        switch( $action ) {
            case 'add':
            case 'insert':
                $this->wp_insert_post( $postarr );
                break;
            case 'edit':
            case 'update':
                $this->wp_update_post( $postarr, $synced_id );
                break;
            case 'delete':
                $this->wp_delete_post( $postarr, $synced_id );
                break;
        }
        restore_current_blog();
    }

    /**
     * Switches to the site we need to make a change on a post then
     * it fetches the real post id using 'get_post_from_synced_id'
     * then calls update_post_meta | delete_post_meta
     *
     * @param string $action     The action/event type: update|delete
     * @param int    $meta_id    ID of the row being added
     * @param int    $object_id  ID of the object the metadata applies to
     * @param string $meta_key   Metadata key
     * @param mixed  $meta_value Metadata value
     * @param int    $blog_id    ID of the site/blog
     * @param int    $synced_id  Synced ID
     */
    protected function wp_meta_action( $action, $meta_id, $object_id, $meta_key, $meta_value, $blog_id, $synced_id ) {
        switch_to_blog( $blog_id );

        $id = $this->get_post_from_synced_id( $synced_id );

        switch( $action ) {
            case 'update':
                update_post_meta( $id, $meta_key, $meta_value );
                break;
            case 'delete':
                delete_post_meta( $id, $meta_key, $meta_value );
                break;
        }
        restore_current_blog();
    }

    /**
     * Deletes synced posts by fetching the real post id using 'get_post_from_synced_id'
     * then calls wp_delete_post.
     *
     * @param array $postarr   An array of post data
     * @param int   $synced_id Synced ID
     */
    protected function wp_delete_post( $postarr, $synced_id ) {
        $id = $this->get_post_from_synced_id( $synced_id );

        wp_delete_post( $id );
    }

    /**
     * Updates synced posts by fetching the real post id using 'get_post_from_synced_id'
     * then calls wp_update_post.
     *
     * @param array $postarr   An array of post data
     * @param int   $synced_id Synced ID
     */
    protected function wp_update_post( $postarr, $synced_id ) {
        $id          = $this->get_post_from_synced_id( $synced_id );
        $postarr     = $this->set_post_id( $id, $postarr );

        wp_update_post( $postarr );
    }

    /**
     * Adds the synced posts using `wp_insert_post` then attaches synced id meta
     *
     * @param array $postarr An array of post data
     */
    protected function wp_insert_post( $postarr ) {
        $original_id = $postarr['ID'];
        $postarr     = $this->set_post_id( false, $postarr );
        $id          = wp_insert_post( $postarr, false );

        $this->add_sync_meta( $id, $original_id );
    }

    /**
     * Returns an array of blog/site ids excluding the current site.
     *
     * @return array An array of blog/site ids
     */
    protected function get_blog_ids_without_current_site() {
        $sites = get_sites( array( 'site__not_in' => array( get_current_blog_id() ) ) );
        $blogs = array();

        foreach ( $sites as $site ) {
            $blogs[] = $site->blog_id;
        }

        return $blogs;
    }

    /**
     * Sets or unsets the post id when given a post array
     *
     * @param  int   $id      New post ID - if set to false it unset current post id
     * @param  array $postarr An array of post data
     * @return array          New post array with updated id
     */
    protected function set_post_id( $id, $postarr ) {
        if ( $id === false ) {
            unset( $postarr['ID'] );
            return $postarr;
        } else {
            $postarr['ID'] = $id;
        }
        return $postarr;
    }

    /**
     * Adds the synced id meta to an attachment
     *
     * @param int $attachment_id Attachment ID of the post we want to attach meta too
     * @param int $original_id   Attachment ID of the original attachment
     */
    protected function add_sync_meta( $attachment_id, $original_id ) {
        update_post_meta( $attachment_id, self::$meta, $original_id );
    }

    /**
     * Returns the real post id using our synced id post meta
     *
     * @param  int $synced_id Synced ID
     * @return int            ID of the found post
     */
    protected function get_post_from_synced_id( $synced_id ) {
        return $this->get_post_id_from_meta( self::$meta, $synced_id );
    }

    /**
     * Returns a post id be searching the post meta table where
     * key and value both match.
     *
     * @param  string $key   The meta_key to search for.
     * @param  string $value The meta_value to search for.
     * @return int           ID of the found post
     */
    protected function get_post_id_from_meta( $key, $value ) {
        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %d",
            $key,
            $value
        );
        return $wpdb->get_var( $query );
    }

}
