# Shapes: other ways to say a value

A key's definition is its owner's canonical contract: what the key
holds, and what is stored. A *shape* is an alter layer on top of it.
Whoever contributes one describes another path to the same stored value
— its own input definition, and the conversion to the canonical and
back — and never changes the canonical itself.

Core does this everywhere, and keeps it in widgets, where only a form
can reach it:

- The default datetime widget and the date list widget accept different
  inputs and store the same string.
- An entity reference is an autocomplete, a select or a set of buttons
  for one stored id.
- The link widget turns a typed path into a URI.

A shape is the same idea said as data, so a payload, a tool, a config
action and a form all reach it.

## The model in one example

`data_surface_demo_extras` mounts a review deadline on the content type
surface. What is stored is one integer of seconds, so that is the
canonical: an integer from 3600 to 2592000, described as seconds. A
person says a deadline as an amount and a unit, so the extras module
contributes that as a shape. `data_surface_demo_duration`, a module that
owns nothing here, contributes an ISO 8601 duration as another. All of
these store `604800`:

```json
{"review_deadline": 604800}
{"review_deadline": {"amount": 1, "unit": "weeks"}}
{"review_deadline": "P1W"}
{"review_deadline": {"@shape": "iso8601", "@value": "P1W"}}
```

## Contributing a shape

```php
$event->builder->addShape(
  'third_party_settings.data_surface_demo_extras.review_deadline',
  'iso8601',
  new Iso8601DurationShape($definition),
  'data_surface_demo_duration',
);
```

`DataSurfaceBuilderInterface::addShape(string $key, string $id,
DataSurfaceShapeInterface $shape, ?string $contributor = NULL)`. The key
is dotted to reach into a map; the id is unique on the key and never
starts with `@`; the contributor is the module's name, or `NULL` for the
surface's owner. Any module may call it from the build event, including
one that does not own the key.

A shape implements `DataSurfaceShapeInterface`:

| Method | Says |
| --- | --- |
| `getInputDefinition()` | What the shape accepts: a map or a scalar, labeled, with its own constraints and defaults. |
| `toCanonical($input)` | The canonical value for an accepted, valid input. `NULL` means the key holds nothing. |
| `fromCanonical($stored)` | The stored value said in this shape. Must tolerate `NULL` and values that are not canonical at all. |
| `isLossy()` | Whether two inputs can store the same canonical, so the inverse is the nearest exact spelling rather than what was typed. |

A shape is pure and serializable like everything else a surface carries:
no services, no site state, no closures. The demo shapes take their input
definition through the constructor, built by the subscriber that has the
translation service.

Shapes are recorded when contributed and attached to the canonical
definition at seal, through `DefinitionMetadata::setShapes()`, so they
travel wherever the definition does: nested in a map, through
refinement, into a form and into an emitted schema. A contributor may add
one before the key's owner has declared the key.

### What sealing refuses

- A key the surface does not declare, or one inside a mount or a slot —
  a mounted child's keys are the child's, so a shape for one is
  contributed when the child surface is built.
- A key typed `any`, a list, or a secret.
- A top-level key another key refines against, because a refiner reads
  the value as it was sent.
- A shape whose input is `any`.
- **Ambiguity.** Two readings of one key that a single input could fit,
  canonical included, are refused with a `\LogicException` naming both:
  the same kind of value — numbers (integer and float together),
  strings, booleans — or two maps sharing a property name. The rule is
  not allowed to guess, so it is never asked to.

`addShape()` itself refuses an id starting with `@` and a second shape
of the same id on one key, naming who contributed the first.

## The canonical is always accepted

Shapes are additive alternates. A caller may send the canonical value,
any shape's input, or name the shape it means; nothing may alter the
canonical. A policy filter may remove a shape — "this site does not take
that spelling here" — with `DefinitionMetadata::withoutShape()`, and the
narrowing check holds that to remove-only: a filter or refiner that adds
a shape, or replaces one, is refused like any other widening. Shapes are
compared by identity, and refinement copies a definition but shares the
shapes on it, so a refiner that mutates the definition it was handed
keeps them; one that builds a fresh definition has removed them, which
is allowed and rarely meant.

## Reading a value: union, or explicit

**Explicit.** A payload names the shape with the reserved
`DataSurfacePipelineInterface::SHAPE` key, `@shape`, and
`SHAPE_VALUE`, `@value`, beside it:

```json
{"@shape": "amount_unit", "@value": {"amount": 2, "unit": "weeks"}}
```

It is spelled in the same reserved `@` namespace as `@access`,
`CLEAR_SECRET` and `KEEP_STALE`: no definition names a key with a
leading `@`, so a map carrying one is never a value of any shape. The
selector holds those two keys and nothing else. A named shape the key
does not take — never contributed, or removed by a filter — is refused
by name, listing the shapes the key does take.

**Union.** Without a selector, `accept()` applies the matching rule:

1. The canonical is tried first, then each shape in the order it was
   contributed.
2. A reading **fits** an input when accepting it as that reading lands
   in the reading's own kind: an integer for a number, a map with only
   that map's keys, and — because every scalar casts to one — only a
   string that arrived as a string for a string reading.
3. The input is read by the first reading it fits **and** whose own
   constraints it satisfies.
4. If none takes it but exactly one fits, that is the one it was meant
   for, and its own refusal is reported: forty-five days sent as an
   amount and a unit is refused in days, on the amount.
5. Otherwise it is the canonical's value and the canonical's refusal,
   with one more violation naming the shapes that were tried. An input
   no reading can even hold is refused as a shape mismatch whose
   expected type names them too.

A value read in a shape leaves `accept()` as the selector naming it, so
the later stages know which gates and which conversion apply; a value
read as the canonical leaves bare. Nothing is converted in `accept()`.

## Both gates

`validate()` runs two gates on every value sent in a shape, at any
depth:

1. The shape's own constraints judge what was sent, and their violations
   are filed at the key's path, inside it where the constraint says so
   (`...review_deadline.amount`).
2. The value is converted with `toCanonical()`, and the canonical's
   constraints judge the result together with the rest of the key.

When the first gate refuses, there is no canonical to judge and the
second adds nothing. `P45D` passes the first and is refused by the
canonical's Range; `P1M` is refused by the first, because a month is not
a fixed number of seconds.

## Conversion happens in prepare

`DataSurfacePipelineInterface::canonical()` turns every shaped value
into its canonical, and `prepare()` calls it before it hands values to a
target, so a target — and therefore commit — only ever sees canonical
values. `submit()` reports the canonical values on a valid run, dry run
included, so a caller reading the result reads what was stored. A host
that stores accepted values itself rather than through a target calls
`canonical()` too: `DataSurfaceConfigurationTrait::setConfiguration()`
and the formatter host's settings callback do.

## The display chooses

A generated form renders each key as its canonical, unless the host's
form display names a shape for it. The setting is a small serializable
array, dotted key to shape id:

```php
['third_party_settings.data_surface_demo_extras.review_deadline' => 'amount_unit']
```

| Host | Where the setting lives |
| --- | --- |
| A route-served provider form (`DataSurfaceProviderForm`) | `Form\DataSurfaceShapeDisplayInterface::surfaceFormShapes()`, on the route's cosmetics service or on the provider, found where the cosmetic layer is. The content type demo's `NodeTypeSurfaceFormCosmetics::SHAPES` chooses the amount and unit. |
| A plugin host (block, condition, action, formatter, field type) | `DataSurfaceHostTrait::surfaceShapeDisplay()`, empty by default; override it. |
| A form of your own | The `$shape_display` argument of `buildSurfaceForm()`. |

A choice naming a key or a shape the surface does not have — the
contributing module is not installed, a filter removed the shape — is
ignored, never refused: presentation does not break a form.

`Form\SurfaceShapeDisplay` does the work. The key's definition is
swapped, at whatever depth, for the shape's input definition wearing
the key's label, so widgets draw it without knowing a shape is involved.
The stored value is shown through `fromCanonical()`. The effective choice
rides on the container as `#data_surface_shape_display`, a plain array
per the serialization rule, and extraction reads each displayed key
through the same swapped definition and hands the pipeline its value
wrapped in the selector — so a form never relies on the matching rule.

### Lossy inverses

A canonical always round-trips through a shape; the way it was said does
not always. Ten business days are stored as the seconds of twelve
calendar days and come back as twelve days; `P1W2D` is stored as nine
days and comes back as `P9D`. A lossy shape says so, and a form
displaying it appends to the element's description that what is stored
is shown in its nearest exact spelling. Stale and discard rules are
unaffected: they are about stored values and in-progress input, and a
shape changes neither.

## Emission, and what the Tool API cannot say

The precise JSON Schema for a key with shapes is a `oneOf` at the key:
the canonical's schema, each shape's input schema, and the selector
object. The Tool API cannot carry it:

- An input definition holds one data type, and the normalizer writes a
  schema from that type and the constraints it knows, and nothing else.
- The normalize event lets a subscriber swap one definition for another
  at any depth, but not write schema keywords, so no subscriber can emit
  `oneOf` either.
- Unlike a slot's union, which is decided by a sibling and would need
  `if`/`then` at the parent, this one is decided by the value itself, so
  a per-key `oneOf` would be enough — and is exactly what is missing.

So `data_surface_tool`'s `SurfaceInputDefinitions` emits the most honest
thing it can. The key is typed `any`, carrying no constraints, because
any of the canonical's would refuse every shape before the tool ran.
Its description names every reading — the canonical first, then each
shape with its id, the module that contributed it, its label, and its
type, bounds, pattern and allowed values in words — and says how to name
one with `@shape`. Its `examples` hold every example the canonical and
each shape declare, plus one selector. The tool accepts every reading
and the selector, and both gates run in the pipeline.

Two Tool API artifacts are worth knowing when reading the emitted
schema. Core's normalizer for an `any` value adds a `$comment` saying no
schema is defined for the type, which is true and unavoidable from here.
And because the key carries no constraints, the Tool API's own pre-run
validation says nothing about it: a refusal arrives in the pipeline's
path spelling (`third_party_settings.data_surface_demo_extras.review_deadline.amount`),
not the Tool API's.

The follow-up is a union input definition in the Tool API: a list of
alternative definitions normalized to `oneOf`, with the selector as one
more alternative.

## Shapes, variants and storage translations

Three things in this module translate between a value and another form
of it, and they are different jobs:

| | Changes | Declared with |
| --- | --- | --- |
| **Shape** | What is accepted for a key. The stored value and its definition are untouched. | `addShape()` |
| **Variant** | What a key stores: a sibling chooses which of several shapes of map the key holds. | `mountVariants()`, see [Nesting](nesting.md) |
| **Storage shape** | How a whole set of values is written down, when storage holds something the surface does not declare. | `SettingsShapeInterface`, see [Targets](targets.md#settingsshapeinterface) |

`setThirdPartyShape()`, the storage shape for a contributor's whole
namespace, stays for a namespace whose storage cannot be declared as
definitions. Where it can — which a config schema requires anyway —
declare the stored value as the key's canonical and contribute the
friendlier input as a shape: the stored shape is then advertised, a
caller may send either, and every target, not only the one that writes
third party settings, sees canonical values. The extras demo moved this
way.

## See also

- [Targets](targets.md) for storage shapes and the prepare stage.
- [Generated forms](forms.md) for the display choice in each host.
- [Widgets](widgets.md) for how a widget and a shape differ.
- `modules/data_surface_demo_extras` and
  `modules/data_surface_demo_duration` for the two contributions, and
  `modules/data_surface_demo_node_type_tool/COMPARISON.md` for what each
  tool does with each reading.
