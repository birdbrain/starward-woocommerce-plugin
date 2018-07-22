# Starward WooCommerce Plugin

## Custom Endpoints
```
HTTP GET: ${WP_URL}/wp-json/starward/products/filters/category/${categoryId}
```
- Gets product filters for a specific category specified by its ID

## Extending Existing Functions
```
filter_product_category_multiple_attributes
```
- Extends the WooCommerce query for products in _class-wc-rest-products-controller.php_
- Allows for filtering by multiple attributes by their slug
- E.g. ```http://localhost:3000/store/living?pa_size=28&pa_color=23```

```
filter_woocommerce_rest_prepare_product_object
```
- Extends the WooCommerce single product response

## WP Functions
```
get_term_meta
```
- Grabs meta data from the WP database
- Used to get swatch hex values when extending the product response

```
get_terms
```
- Gets all attribute terms belonging to the attribute specified by its taxonomy (i.e. pa_color, pa_size...)

```
wc_get_attribute_taxonomies
```
- Gets all product attributes (name, label, id...)

```
wc_get_attribute_taxonomy_names
```
- Returns an array of attribute taxonomies (i.e. [pa_color, pa_size...])

```
wc_get_product
```
- Returns a product object
