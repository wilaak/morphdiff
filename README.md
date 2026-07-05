# Morphdiff (PHP)

```php
require 'morphdiff.php';

//
// One-shot comparison
//

$ops = morphdiff\compare($old_html, $new_html);
foreach ($ops as $op) {
    // $op['selector']  CSS selector to patch (#id or a structural path)
    // $op['html']      the element's new outer HTML
}
```

```php
//
// Incremental patches
//

$v = morphdiff\view_new($initial_html);

for (;;) {
    // $next = your_page_renderer();
    $next = '<html><body><p id="price">42.10</p></body></html>';

    $ops = morphdiff\view_update($v, $next);   // $v holds the prior render

    // send_patches_to_client($ops);
    //
    // ^ do this or alternatively:
    //
    // foreach ($ops as $op) {
    //     printf("patch %s -> %s\n", $op['selector'], $op['html']);
    // }
}
```

An identical render returns `[]`. `view_update` takes the view by reference, so capture it with `use (&$v)`, not an arrow function.