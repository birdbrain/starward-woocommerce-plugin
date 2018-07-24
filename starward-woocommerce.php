<?php
/**
 * Plugin Name:       Starward WooCommerce
 * Plugin URI:        https://github.com/birdbrain/starward-woocommerce-plugin
 * Description:       This plugin creates custom API endpoints and extends existing WooCommerce REST API responses
 * Version:           1.0.0
 * Author:            BirdBrain
 * Author URI:        hello@birdbrain.com.au
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       starward-woocommerce
 */


/* ------------------------------------------------------------------------
  Manipulating the WooCommerce Products Query to allow filtering
  by attribute slug and term ids ( e.g. ?pa_color=21,22&pa_size=30 )
------------------------------------------------------------------------ */
function filter_product_category_multiple_attributes( $query ) {
  if ($query->is_main_query()) {
    return;
  }
  // // Filter by multiple attributes and terms.
  foreach ( wc_get_attribute_taxonomy_names() as $attribute ) {
    if ( isset($_GET[$attribute]) ) {
  		$array = array(
  			'relation' 	 => 'AND'
  		);
  		foreach ( wc_get_attribute_taxonomy_names() as $attribute ) {
  			if ( isset($_GET[$attribute]) ) {
  				$array[] = array(
  					'taxonomy' => $attribute,
  					'field'    => 'term_id',
  					'terms'    => explode(',', $_GET[$attribute]),
  					'operator' => 'IN'
  				);
  			}
  		}
      $tax_query = $query->get( 'tax_query' );
      $tax_query[] = $array;
  		$query->set( 'tax_query', $tax_query );
  		break;
    }
  }
  return $query;
}
add_action( 'pre_get_posts', 'filter_product_category_multiple_attributes' );


/* ------------------------------------------------------------------------
  Manipulating the WordPress Product response
------------------------------------------------------------------------ */
function filter_woocommerce_rest_prepare_product_object( $response, $object, $request ) {
  if( empty( $response->data ) ) {
    return $response;
  }

  $attribute_taxonomies = wc_get_attribute_taxonomies();

  // Loop through the attributes on current product
  $attributes = $response->data['attributes'];
  foreach($attributes as $attrkey => $attribute) {

    /* ########################################################
      - Adding new swatch key to attribute response for color attributes,
        which holds the hex code for each swatch color option
    ######################################################## */
    // Get an array of attributes whose attribute type is color
    $color_type_attribute_taxonomies = array_filter($attribute_taxonomies, function($attribute_taxonomy) {
      return $attribute_taxonomy->attribute_type == 'color';
    });
    // Loop through the color type attributes
    foreach($color_type_attribute_taxonomies as $tax_object) {
      //Check if current attribute is a color type attribute
      if ($attribute['id'] == $tax_object->attribute_id) {
        // Get current attribute's options
        $options = $response->data['attributes'][$attrkey]['options'];
        // Get current attribute's terms
        $color_terms = get_terms('pa_' . $tax_object->attribute_name);
        foreach( $options as $option ) {
          foreach($color_terms as $term) {
            if ($term->name == $option) {
              // Add a new swatch with hex value for each color option
              $response->data['attributes'][$attrkey]['swatches'][$option] = get_term_meta( $term->term_id, 'product_attribute_color', true);
            }
          }
        }
      }
    }

    /* ########################################################
      - Adding attribute taxonomy to the attribute response
      - Adding attribute identifier to the attribute response
      - Adding more detailed option data to the attribute options response
    ######################################################## */
    foreach($attribute_taxonomies as $attribute_taxonomy) {
      if ($attribute['id'] == $attribute_taxonomy->attribute_id) {

        /* Add slug to current attribute response */
        $response->data['attributes'][$attrkey]['taxonomy'] = ('pa_' . $attribute_taxonomy->attribute_name);

        /* Add attribute identifier to current attribute response */
        $response->data['attributes'][$attrkey]['slug'] = $attribute_taxonomy->attribute_name;

        /* Replace default options data with detailed options data for current attribute */
        $options = $response->data['attributes'][$attrkey]['options'];
        $new_options = array();
        $attribute_terms = get_terms('pa_' . $attribute_taxonomy->attribute_name);

        foreach( $options as $option ) {
          foreach($attribute_terms as $attribute_term) {
            if ($attribute_term->name == $option) {
              $new_options[] = (object) [
                id => $attribute_term->term_id,
                name => $attribute_term->name,
                slug => $attribute_term->slug,
                taxonomy => $attribute_term->taxonomy,
                description => $attribute_term->description,
                count => $attribute_term->count
              ];
            }
          }
        }
        $response->data['attributes'][$attrkey]['options'] = $new_options;
      }
    }
  }

  /* ########################################################
    - Replacing Variation IDs with Variation details
  ######################################################## */

  // Get the current product object
  $variation_ids = $response->data['variations'];

  $detailed_variations = array_map(function($variation_id) {
    $variation = wc_get_product($variation_id);
    return (object) [
      'variation_id' => $variation->get_id(),
      'image_url' => wp_get_attachment_url($variation->get_image_id()),
      'variation_regular_price' => $variation->get_regular_price(),
      'variation_sale_price' => $variation->get_sale_price(),
      'attributes' => $variation->get_attributes(),
      'is_on_sale' => $variation->is_on_sale()
    ];
  }, $variation_ids);

  $response->data['variations'] = $detailed_variations;


  /* ########################################################
    - Get ALL Variation attributes for a product
  ######################################################## */
  if ($response->data['type'] == 'variable') {
    $variation_attributes = wc_get_product($response->data['id'])->get_variation_attributes();
    $variation_attributes = array_map(function($attribute) {
      return (array_values($attribute));
    }, $variation_attributes);
    $response->data['variation_attributes'] = $variation_attributes;
  }



  /* Return new response */
  return $response;
}
add_filter( 'woocommerce_rest_prepare_product_object', 'filter_woocommerce_rest_prepare_product_object', 10, 3 );

/* ------------------------------------------------------------------------
  API Endpoint to get all product filters for a specific category
------------------------------------------------------------------------ */
add_action('rest_api_init', function () {
	$namespace = 'starward/';
 	register_rest_route( $namespace, '/products/filters/category/(?P<category_id>.*?)', array(
 		'methods'  => 'GET',
 		'callback' => 'get_product_category_filters',
 	));
});

function get_product_category_attribute_terms($category_id) {
  // Get products in category id selected
  $args = array(
    'post_type'             => 'product',
    'post_status'           => 'publish',
    'ignore_sticky_posts'   => 1,
    'posts_per_page'        => -1, // return all products (offset ignored with -1)
    'tax_query'             => array(
      array(
        'taxonomy'      => 'product_cat',
        'field'         => 'term_id', //This is optional, as it defaults to 'term_id'
        'terms'         => $category_id,
        'operator'      => 'IN' // Possible values are 'IN', 'NOT IN', 'AND'.
      ),
      array(
        'taxonomy'      => 'product_visibility',
        'field'         => 'slug',
        'terms'         => 'exclude-from-catalog', // Possibly 'exclude-from-search' too
        'operator'      => 'NOT IN'
      )
    )
  );
  $products_response = new WP_Query($args);
  $products = $products_response->posts;
  // Get product attribute details
  $attribute_taxonomies = wc_get_attribute_taxonomies();
  // Initialize response array
  $category_attribute_terms = array();
  // For each product attribute
  foreach ($attribute_taxonomies as $attribute_taxonomy) {
    // Get all attribute options for each product in the category
    $options = array_map(function($product) use ($attribute_taxonomy) {
      $terms = get_the_terms($product->ID, 'pa_' . $attribute_taxonomy->attribute_name);
      return $terms ? $terms : [];
    }, $products);
    // Remove duplicate options
    $unique_options =
      array_values(
        array_unique(
          array_merge(...$options),
          SORT_REGULAR
        )
      );
    // Push attribute details and options to attribute terms array
    $category_attribute_terms[] = (object) [
      'id' => $attribute_taxonomy->attribute_id,
      'name' => $attribute_taxonomy->attribute_name,
      'label' => $attribute_taxonomy->attribute_label,
      'slug' => 'pa_' . $attribute_taxonomy->attribute_name,
      'options' => $unique_options
    ];
  }
  return $category_attribute_terms;
}

function get_product_category_subcategories($category_id) {
  $args = array(
     'hierarchical' => 1,
     'show_option_none' => '',
     'hide_empty' => 0,
     'parent' => $category_id,
     'taxonomy' => 'product_cat'
  );
  $subcats = get_categories($args);
  return $subcats;
}

function get_product_category_price_min_max($category_id) {
  // Get products ordered by price (lowest to highest)
  $args = array(
    'post_type'             => 'product',
    'post_status'           => 'publish',
    'orderby'               => 'meta_value_num',
    'meta_key'              => '_price',
    'order'                 => 'asc',
    'ignore_sticky_posts'   => 1,
    'posts_per_page'        => -1, // return all products (offset ignored with -1)
    'tax_query'             => array(
      array(
        'taxonomy'      => 'product_cat',
        'field'         => 'term_id', //This is optional, as it defaults to 'term_id'
        'terms'         => $category_id,
        'operator'      => 'IN' // Possible values are 'IN', 'NOT IN', 'AND'.
      ),
      array(
        'taxonomy'      => 'product_visibility',
        'field'         => 'slug',
        'terms'         => 'exclude-from-catalog', // Possibly 'exclude-from-search' too
        'operator'      => 'NOT IN'
      )
    )
  );
  $products_response = new WP_Query($args);
  $products = $products_response->posts;

  // Get lowest and highest price from first and last product
  $min_price = wc_get_product( reset($products)->ID )->get_price();
  $max_price = wc_get_product( end($products)->ID )->get_price();
  return (object) [
    'min_price' => $min_price,
    'max_price' => $max_price
  ];
}

function get_product_category_filters($data) {
  // Get the category id from the request query
  $category_id = $data['category_id'];
  // Return category filters object
  return (object) [
    'attributes'      => get_product_category_attribute_terms($category_id),
    'subcategories'   => get_product_category_subcategories($category_id),
    'price'           => get_product_category_price_min_max($category_id)
  ];
}
?>
