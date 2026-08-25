<?php

/*
 * Content types for `CmsPage::types()`.
 *
 * The Cms module itself is deferred — its tables carry no `business_id`, so it is
 * not tenant-scoped and no screen can safely be built on it yet (NOTES §17).
 * These keys exist anyway because a missing namespace is a defect whether or not
 * a screen renders it today, and three words is cheaper than an exception to
 * document.
 */

return [
    'page' => 'Page',
    'blog' => 'Blog',
    'testimonial' => 'Testimonial',
];
