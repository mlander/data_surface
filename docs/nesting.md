# Nesting: mounts and slots

Some values are not a list of keys but a surface inside a surface: a
field instance's settings are the field type's own surface, a block's
presentation settings are one shape for a list and another for a grid.
This page is how a surface says that, and the rule that decides how.

## Shape is declared, values are refined

> **A surface declares its shape statically. Refinement may only tighten
> values.**

Refinement exists to answer "given what the other keys hold, which
values does this key allow?" — which bundles for this entity type,
which variants for this casing. It never answers "which keys exist?",
and it is not allowed to: a caller that read the surface once, a form
built from it, or a schema emitted from it would otherwise be told about
keys that did not exist when it looked.

So when what a key *holds* depends on something, the dependency is
declared as shape, up front, and refinement only picks within it. Core
is full of the same situation, and every instance of it is the same
choice made by hand:

- A field's settings vary by field type: a field config's `settings`
  holds one shape for an entity reference and another for a list.
- A Views filter's value element varies by operator: one text box for
  "contains", two for "between", none for "is empty".
- The media type form varies by source plugin: each source contributes
  its own configuration form.

Each of those builds the varying part in form code, after the fact, so
nothing but the form knows the full set of shapes. A mount or a slot
says the same thing as data.

## The four shapes of "it depends"

Every case above is one of four shapes. This module now declares the
first two; the other two are recorded as next.

| Shape | What decides the shape | Example | Here |
| --- | --- | --- | --- |
| **Variant by sibling** | One sibling key's value picks one of a fixed set of shapes. | A block's list or grid settings; a Views operator's value. | `mountVariants()` |
| **Fixed child by address** | The subject the surface is about names another surface, whole. | A field instance's settings are its field type's surface. | `mount()` |
| **Collection of variants** | A list whose items each pick their own shape. | A list of Views filters, each with its own plugin's settings. | Next: item 12's `*` segment plus a slot per item. |
| **Contributor with own storage** | Another module mounts settings it stores itself. | Third-party settings on a content type. | Next: today `setThirdPartyDefinition()`; contributor refiners on a mounted key need D6 paths. |

A fifth case is not "it depends" at all and is listed so it is not
mistaken for one: a key **locked against stored state** (a machine name
on edit) narrows its value space to one value. That is `lock()`, and
it is a value, not a shape.

## Mounting a child: `mount()`

```php
$builder->setDefinition('settings', MapDataDefinition::create()
  ->setLabel(new TranslatableMarkup('Field instance settings')));
$builder->mount('settings', new DataSurfaceCoordinate(
  'field_type:address',
  'field_settings',
  'node.article.field_address',
));
```

The key's definition becomes a map whose property definitions **are**
the child's definitions, in the child's order, under the label the
parent gave the key (an empty map set beforehand is kept as that shell).
The entry carries a `SurfaceMount`: the sealed child and, when it was
named by one, its coordinate.

A child is given one of three ways:

| Child | When | What happens at seal |
| --- | --- | --- |
| `DataSurfaceCoordinate` | Preferred whenever the child is somebody else's surface. | The factory resolves it through the resolver registered for the host type; the child's own build event runs; the address is kept on the entry, so whatever advertises the parent can name the child. |
| `DataSurfaceInterface` | A caller already holds the sealed child. | Used as it is. |
| A callable taking a builder | A small child that is part of the parent's own declaration. | Said into a fresh builder and sealed. No refiner is bound and no build event of its own is dispatched. |

A coordinate is resolved by the factory, so a builder holding one can
only be sealed through `DataSurfaceFactoryInterface::build()`; sealing
it by hand throws, and so does a coordinate no resolver serves. A
resolver is a service tagged `data_surface.surface_resolver`
implementing `DataSurfaceResolverInterface`; `data_surface_tool`'s
`FieldTypeSurfaceResolver` is the shipped one, for
`field_type:<type>` / `field_settings` / `<field config id>`.

What crosses a mount, and how:

- **Refinement runs in the child's frame.** When the parent is refined,
  the child is refined against the value at the mount, and its refiners
  are dispatched under the child's own key names. A parent's refiner
  never matches a child key, and no refiner ever sees a dotted path.
- **The child's contributors and filters already ran** when the child
  was built. Mounting it does not run them again.
- **Values are the child's to judge.** `accept()` and `validate()` hand
  the mounted value to the child surface, so its locked keys, secrets,
  defaults, required messages and stale rule all apply, and every
  violation is filed under the mount key with the child's key at the
  front of its path: `settings.field_overrides.country_code`.
- **Defaults** are the child's defaults.
- **Cacheability** of the child joins the parent's at seal, and what the
  child's refiners declare joins the refined parent.
- **Storage** goes through the child's own target when there is one:
  `MountTarget` routes each mount's value to a target given the child
  surface, not the parent. Pair it with other targets in a
  `CompositeTarget` when the parent holds keys beside its mounts.

## Choosing a shape: `mountVariants()`

```php
$builder->setDefinition('presentation', DataDefinition::create('string')
  ->setLabel(new TranslatableMarkup('Presentation'))
  ->setRequired(TRUE)
  ->addConstraint('LabeledChoice', ['choices' => [
    'list' => new TranslatableMarkup('List'),
    'grid' => new TranslatableMarkup('Grid'),
  ]]));
$builder->setDefault('presentation', 'list');

$builder->setDefinition('presentation_settings', MapDataDefinition::create()
  ->setLabel(new TranslatableMarkup('Presentation settings')));
$builder->mountVariants('presentation_settings', 'presentation', [
  'list' => static::declareListPresentation(...),
  'grid' => static::declareGridPresentation(...),
]);
```

Each variant is a child, given any way `mount()` takes one. The
recommended spelling for a variant that belongs to its host is the one
above: a protected static method with the same signature as
`declareDataSurface()`, handed over as a first-class callable. It reads
as a declaration, it is static like the rest of the declaration, and
the callable is consumed at seal, so nothing a surface carries holds a
closure. Reach for a coordinate instead when a variant is another
host's surface — the plugin-selects-plugin case, where a variant is the
chosen plugin's own surface reused, never restated.

Statically, the surface advertises everything:

- **The discriminator.** `presentation` gains a `Choice` over the
  variant ids, or, as above, keeps its own labeled list narrowed to
  them. A variant the discriminator does not allow is refused at
  `mountVariants()`; at seal the discriminator's values and the variant
  ids must agree exactly, so a contributor that extends the
  discriminator with a value nobody gave a variant is refused there.
- **Every variant's shape**, on the entry's `SurfaceSlot`:
  `$surface->getDefinitions()->entry('presentation_settings')->slot`.
- **A placeholder for the slot itself**, typed `any`, carrying the
  slot's label and description, and marked as a slot:
  `DefinitionMetadata::slotOf($definition)` answers the discriminator's
  key. `any` because until the discriminator holds a value the only true
  statement about the type is "one of these".

The slot refines against its discriminator, so with a value for
`presentation` the slot's definition is **exactly** that variant's map,
wearing the slot's label. That is the one move from a placeholder to a
shape refinement allows, and it is allowed because the shape was
declared before anything was chosen.

What a slot does with values:

- **Accept** reads the discriminator first and hands the slot's value to
  the chosen variant's child. If what the slot held was written for
  another variant — the discriminator moved — it starts again from the
  chosen variant's defaults rather than carrying keys that mean nothing
  there.
- **A payload for another variant is a violation on the slot**, by path:
  sending `{"presentation": "grid", "presentation_settings":
  {"show_summary": true}}` is refused with
  `presentation_settings.show_summary belongs to the list variant, but
  presentation chose grid.`
- **The chosen variant's rules hold** at their own paths:
  `presentation_settings.columns`.
- **A slot value with no discriminator value** is refused as having no
  shape to be read by.
- **Defaults**: the slot starts from the variant the discriminator's own
  default chooses.

### In a generated form

The discriminator is a refinement dependency like any other, so it
carries the AJAX that rebuilds the container, and the slot renders as
the chosen variant's map. When the discriminator changes, the input the
browser sends back for the slot belongs to the variant that was on the
screen; the discard rule withdraws it as orphaned — the same rule that
withdraws a bundle orphaned by a new entity type — and the rebuilt slot
starts from the new variant's defaults. A slot whose discriminator
holds nothing is not rendered at all. See [Generated forms](forms.md).

### In config schema

Config schema already has a spelling for a slot, and a host storing one
uses it: a type chosen by the sibling key.

```yaml
presentation_settings:
  type: block.settings.data_surface_demo.presentation.[%parent.presentation]
```

## What the Tool API can carry

`data_surface_tool`'s `SurfaceInputDefinitions` converts a mount to a
nested map input whose properties are the child's keys, locks included,
and a resolved slot to exactly its variant's map.

An unresolved slot is the gap. The precise JSON Schema is a
discriminated union decided by a sibling: at the level of the object
holding both keys, an `if` on the discriminator's `const` with a `then`
per variant (or a `oneOf` over whole objects). The Tool API cannot
express it: `MapInputDefinition` carries one property list, the
normalizer emits only `properties` and `required` for a map, and the
normalize event swaps one definition for another rather than letting
anything write schema keywords. A `oneOf` inside the slot alone would
not be right either, because the discriminator is not inside it.

So the slot is emitted as the widest honest schema: one map holding
every variant's keys, none required, each saying "Only when
`presentation` is grid." in its description, a key two variants declare
differently widened to `any`, and the slot's description naming the
whole table. That a key belongs to the chosen variant is enforced by
the pipeline. The Tool API follow-up is a union input definition — the
discriminator's name and one definition per value — normalized to
`if`/`then` at the parent level.

## Narrowing inside maps

The narrowing check now reads inside maps and lists. A refined map holds
exactly the properties it was handed, and each property — and a list's
item definition — is held to the same table as a top-level key, at any
depth. The table is in [Refinement](refinement.md#the-narrowing-table).

## What is next

- **Collections of variants**: a list whose items each choose a shape,
  addressed with the `*` segment of D6 paths (roadmap item 12).
- **Contributor targets**: a contributor registering a refiner for the
  key it mounted under `third_party_settings.<provider>`, which needs the
  dotted refinement paths a parent does not have today. Paths *into* a
  mount are dotted already — in violations and in emitted schemas — but
  nothing yet refines from a parent into a child path, by design.
