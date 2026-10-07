# Data Surface Demo Duration

A module that owns nothing on a surface contributing another way to say
a value somebody else's key stores.

## What it shows

`data_surface_demo_extras` mounts a review deadline on every content
type. The key is that module's: it declared the canonical — an integer
of seconds from 3600 to 2592000, which is what is stored — and
contributed an amount and unit shape beside it. This module changes none
of that, and the extras module's code does not know it exists. From the
build event it contributes one more [shape](../../docs/shapes.md),
`iso8601`, under its own name:

```php
$event->builder->addShape(
  NodeTypeReviewSettings::DEADLINE_KEY,
  Iso8601DurationShape::ID,
  new Iso8601DurationShape($duration),
  'data_surface_demo_duration',
);
```

So a caller may now send the deadline as `604800`, as
`{"amount": 1, "unit": "weeks"}`, or as `"P1W"`, and all three store
`604800`. A payload may name the reading it means:
`{"@shape": "iso8601", "@value": "P1W"}`.

- **The input** is a string matching an ISO 8601 duration such as `P1W`,
  `P3D` or `PT12H`. The pattern takes any ISO 8601 duration on purpose,
  so that a second constraint can say why some are refused: months and
  years are not a fixed length of time, so `P1M` has no one number of
  seconds, and minutes and seconds are not whole hours.
- **The conversion** is PHP's `DateInterval`, weeks, days and hours
  only. Both gates hold: `P45D` is a fine duration and is refused by the
  canonical's Range on the 3888000 seconds it becomes.
- **The inverse** says stored seconds in the largest of weeks, days and
  hours that divides them exactly: 604800 is `P1W`, 259200 is `P3D`,
  43200 is `PT12H`. It is lossy — `P1W2D` comes back as `P9D` — and a
  form displaying the shape says it shows the nearest exact spelling.

The shape is generic: `Iso8601DurationShape` fits any key whose
canonical is a whole number of seconds. Only the subscriber names the
review deadline.

The subscriber runs after the default priority, so the union is
advertised with the key owner's shape first. Nothing depends on that:
shapes are attached when the surface is sealed, and sealing refuses any
two readings one input could fit.

## How to try it

```bash
drush pm:install data_surface_demo_duration
```

It depends on `data_surface_demo_extras`, whose key it shapes. With
`data_surface_demo_node_type_tool` installed, the
`data_surface:node_type_add` tool advertises the deadline as the union of
all three readings and takes any of them. The generated content type
form keeps asking a person for an amount and a unit, because that is the
reading its display chooses.

## What gates it

`Kernel\SurfaceShapesTest` stores one week said every way through the
pipeline, the tool and the generated form, and covers the selector, both
gates, a policy filter removing this shape, the display choice and the
lossy inverse. `Kernel\NodeTypeToolComparisonTest` records what each
content type tool does with `"P1W"`, in
[`COMPARISON.md`](../data_surface_demo_node_type_tool/COMPARISON.md).
