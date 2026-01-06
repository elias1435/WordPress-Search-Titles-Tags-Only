<?php
// Keep search to posts only
add_action('pre_get_posts', function ($q) {
  if (is_admin() || ! $q->is_main_query() || ! $q->is_search()) return;

  $q->set('post_type', 'post');
  $q->set('ignore_sticky_posts', true);

  // Force newest first (Elementor/others sometimes override this)
  $q->set('orderby', 'date');
  $q->set('order', 'DESC');
}, 999);


add_filter('posts_join', function ($join, $q) {
  if ( is_admin() || ! $q->is_search() ) return $join;
  global $wpdb;
  if ( strpos($join, 'tt_rel') === false ) {
    $join .= " LEFT JOIN $wpdb->term_relationships AS tt_rel ON ($wpdb->posts.ID = tt_rel.object_id) ";
    $join .= " LEFT JOIN $wpdb->term_taxonomy AS tt_tax ON (tt_tax.term_taxonomy_id = tt_rel.term_taxonomy_id AND tt_tax.taxonomy = 'post_tag') ";
    $join .= " LEFT JOIN $wpdb->terms AS tt_terms ON (tt_terms.term_id = tt_tax.term_id) ";
  }
  return $join;
}, 10, 2);

add_filter('posts_search', function ($search, $q) {
  if ( is_admin() || ! $q->is_search() ) return $search;

  global $wpdb;
  $s = trim( (string) $q->get('s') );
  if ( $s === '' ) return $search;

  $terms = array_filter(array_map('trim', preg_split('/\s+/', $s)));
  if ( empty($terms) ) return $search;

  $per_term = [];
  foreach ($terms as $term) {
    $like = '%' . $wpdb->esc_like($term) . '%';

    $per_term[] = $wpdb->prepare(
      "( $wpdb->posts.post_title LIKE %s OR EXISTS (
          SELECT 1
          FROM $wpdb->term_relationships r
          JOIN $wpdb->term_taxonomy x ON x.term_taxonomy_id = r.term_taxonomy_id AND x.taxonomy = 'post_tag'
          JOIN $wpdb->terms t ON t.term_id = x.term_id
          WHERE r.object_id = $wpdb->posts.ID AND t.name LIKE %s
        ) )",
      $like, $like
    );
  }

  $where_all_terms = '(' . implode(' AND ', $per_term) . ')';

  return " AND $where_all_terms ";
}, 10, 2);

// Avoid duplicates from joins
add_filter('posts_groupby', function ($groupby, $q) {
  if ( is_admin() || ! $q->is_search() ) return $groupby;
  global $wpdb;
  $id = "$wpdb->posts.ID";
  if ( ! $groupby ) return $id;
  if ( strpos($groupby, $id) === false ) $groupby .= ", $id";
  return $groupby;
}, 10, 2);

add_filter('posts_distinct', function ($distinct, $q) {
  if ( is_admin() || ! $q->is_search() ) return $distinct;
  return 'DISTINCT';
}, 10, 2);

add_filter('posts_orderby', function ($orderby, $q) {
  if (is_admin() || ! $q->is_search() || ! $q->is_main_query()) return $orderby;

  global $wpdb;
  $s = trim((string) $q->get('s'));
  if ($s === '') return $orderby;

  $starts = $wpdb->esc_like($s) . '%';
  $any    = '%' . $wpdb->esc_like($s) . '%';

  $case = $wpdb->prepare(
    "CASE
       WHEN $wpdb->posts.post_title LIKE %s THEN 0
       WHEN $wpdb->posts.post_title LIKE %s THEN 1
       WHEN EXISTS (
         SELECT 1 FROM $wpdb->term_relationships r
         JOIN $wpdb->term_taxonomy x ON x.term_taxonomy_id = r.term_taxonomy_id AND x.taxonomy = 'post_tag'
         JOIN $wpdb->terms t ON t.term_id = x.term_id
         WHERE r.object_id = $wpdb->posts.ID AND t.name LIKE %s
       ) THEN 2
       ELSE 3
     END",
    $starts, $any, $any
  );

  // relevance bucket first (ASC), then newest first (DESC)
  return "$case ASC, {$wpdb->posts}.post_date DESC";
}, 9999, 2);

