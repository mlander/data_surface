<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Target\SettingsShapeInterface;

/**
 * The mutable stage a surface passes through before it is advertised.
 *
 * Build time is when widening is legal: other modules may add
 * definitions, extend choices, mount namespaced third-party settings,
 * and register refiners — nothing has been advertised yet, so every
 * consumer sees the identical post-alter surface. seal() produces the
 * immutable surface; from that point on only narrowing refinement may
 * act, and every mutator on this interface throws.
 *
 * Every addition made here is a contribution, and it is recorded under
 * the name of whoever made it. That is what lets refinement be shared:
 * a contributor's refiners narrow the values that contributor added and
 * nothing else, and the refined surface is the union of what each
 * contribution narrowed to. The owner is simply the first contributor —
 * its own definitions, refinement map and refiner are contribution
 * number one — which is why nothing on this interface asks it to name
 * itself.
 *
 * A builder is single use. seal() is idempotent — it answers with the
 * same surface however often it is called — and the factory refuses a
 * builder that has already been sealed, so one builder can never
 * advertise two different surfaces or dispatch the build event twice.
 *
 * @see \Drupal\data_surface\DataSurfaceFactoryInterface
 * @see \Drupal\data_surface\Event\DataSurfaceBuildEvent
 * @see docs/refinement.md
 */
interface DataSurfaceBuilderInterface {

  /**
   * The output key the namespaced third-party outputs are mounted under.
   *
   * The output side's mirror of the input side's 'third_party_settings'
   * map, and spelled differently on purpose: an output is not a setting,
   * and a host that emits both would otherwise have one key meaning two
   * things. The owner may not declare an output of this name, because
   * the key belongs to everybody who mounts under it.
   */
  const THIRD_PARTY_OUTPUTS = 'third_party_outputs';

  /**
   * Gets a definition, so alters can inspect or modify it.
   *
   * @param string $name
   *   The surface key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when the builder holds no such key.
   */
  public function getDefinition(string $name): ?DataDefinitionInterface;

  /**
   * Adds or replaces a definition.
   *
   * Provider/host territory: third parties adding new keys should mount
   * them under their namespace with setThirdPartyDefinition() instead.
   *
   * @param string $name
   *   The surface key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition describing that key.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function setDefinition(string $name, DataDefinitionInterface $definition): static;

  /**
   * Adds or replaces the property definitions of a complex definition.
   *
   * The companion to setDefinition() for maps. Core's MapDataDefinition
   * takes only its own definition array in its constructor and gains its
   * properties through a setter, so a map arrives from its constructor
   * with no properties at all. This is where they are supplied — before
   * seal, so subscribers and every later consumer see the complete map
   * rather than one the host filled in afterwards.
   *
   * Existing properties of the same name are replaced; properties the
   * definition already carries under other names are kept.
   *
   * @param string $key
   *   The surface key naming the map definition.
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $property_definitions
   *   The property definitions, keyed by property name.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the key is unknown or its definition takes no properties.
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function setPropertyDefinitions(string $key, array $property_definitions): static;

  /**
   * Adds or replaces one property of a complex definition.
   *
   * @param string $key
   *   The surface key naming the map definition.
   * @param string $property
   *   The property name within it.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition describing that property.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the key is unknown or its definition takes no properties.
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function setPropertyDefinition(string $key, string $property, DataDefinitionInterface $definition): static;

  /**
   * Sets the default value for a key.
   *
   * Sugar over DefinitionMetadata: the default is written onto the named
   * definition, which is where it belongs and where core's own methods
   * will keep it once they land (see PLAN.md).
   *
   * @param string $name
   *   The surface key.
   * @param mixed $value
   *   The default value; NULL is a declared default, distinct from
   *   declaring none.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the key is unknown.
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function setDefault(string $name, mixed $value): static;

  /**
   * Locks a key: its value is fixed to the declared default.
   *
   * Locking is build-time context (an edit operation locking an
   * identifier, say) — the degenerate refinement, narrowing the value
   * space to exactly one value. Locked keys stay advertised: consumers
   * see the key and its value, rendered disabled in generated forms,
   * and extraction ignores submitted input for them.
   *
   * @param string $name
   *   The surface key.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed, or the name is an output: an
   *   output cannot be locked, because locking narrows what a caller
   *   may send and nothing sends an output.
   */
  public function lock(string $name): static;

  /**
   * Extends an existing definition's declared choices with more values.
   *
   * The contribution API for widening at build time (decision D2): the
   * added values become part of the advertised contract, and the module
   * adding them owns their behavior — in the host's consumption path,
   * and in refinement, where a refiner registered under the same name is
   * handed exactly these values and no others.
   *
   * Widening here is legal precisely because nothing has been advertised
   * yet: every consumer reads the extended surface, never a variant of
   * it.
   *
   * Both choice constraints are understood, and the list is written back
   * in the canonical spelling: `choices` as the list of allowed values,
   * `labels` as what each one is called. A plain Choice has nowhere to
   * keep labels, so contributing them to one drops them. The added
   * values go after the existing ones, and an existing value keeps the
   * label it already had.
   *
   * One value has one owner: contributing a value the key already
   * allows is refused, naming whoever already has it. A key that
   * declares no list of allowed values cannot be extended at all,
   * because giving it one would narrow what its owner left open.
   *
   * @param string $name
   *   The surface key.
   * @param array $choices
   *   The values to add: a list of bare values, or values mapped to
   *   their labels.
   * @param string $contributor
   *   The provider adding them, which is the name its refiners are
   *   registered under.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the key is unknown, or declares no list of allowed values.
   * @throws \LogicException
   *   When a value is already allowed, or the builder is already
   *   sealed.
   */
  public function extendChoices(string $name, array $choices, string $contributor): static;

  /**
   * Mounts a third-party definition under the provider's namespace.
   *
   * Mirrors core's third-party settings storage shape: the sealed
   * surface gains a 'third_party_settings' map with one map per
   * provider, so values land at third_party_settings.<provider>.<key> —
   * advertised, validated, and machine-visible, unlike the form-alter
   * era.
   *
   * A default given here is written onto the mounted definition, so the
   * map's own default assembles itself from its properties.
   *
   * @param string $provider
   *   The module mounting the setting.
   * @param string $key
   *   The setting's key within that provider's namespace.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition describing the setting.
   * @param mixed $default
   *   The default value, written onto the mounted definition.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function setThirdPartyDefinition(string $provider, string $key, DataDefinitionInterface $definition, mixed $default = NULL): static;

  /**
   * Says how a provider's mounted settings are written down.
   *
   * A storage translation over a whole namespace: the mounted
   * definitions describe what a caller sends, and storage holds something
   * the surface never declares. Prefer addShape() for new code. It
   * declares what is stored as the key's canonical definition and
   * contributes the friendlier input as a shape beside it, so the stored
   * shape is advertised, a caller may send either, and every target —
   * not only the one that writes third party settings — sees canonical
   * values. This method stays for a namespace whose storage shape cannot
   * be declared as definitions.
   *
   * The owner's target cannot know about the translation, and the
   * contributor does not build the target, so it travels on the surface:
   * a target that writes third-party settings applies the provider's
   * shape to that provider's namespace, toStorage() on the way in and
   * fromStorage() on the way out, and to nothing else.
   *
   * The shape sees the provider's settings only, keyed by the keys the
   * provider mounted, and must be pure — the SettingsShapeInterface
   * contract — because the surface carrying it is serialized with every
   * form that renders it.
   *
   * @param string $provider
   *   The module whose mounted settings the shape translates. It must
   *   mount at least one definition before the surface is sealed.
   * @param \Drupal\data_surface\Target\SettingsShapeInterface $shape
   *   The translation.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   *
   * @see \Drupal\data_surface\Target\ConfigEntityTarget
   */
  public function setThirdPartyShape(string $provider, SettingsShapeInterface $shape): static;

  /**
   * Contributes another way to say a key's value.
   *
   * The key's definition is its canonical contract and stays exactly as
   * its owner declared it: the canonical value is always accepted, and it
   * is what is stored. A shape is an additive alternate — its own input
   * definition, and the conversion to and from the canonical — that any
   * module may contribute from the build event, including one that does
   * not own the key. A policy filter may remove one; nothing may alter
   * the canonical.
   *
   * On accept the canonical is tried first, then each shape in the order
   * they were added: an input is read by the first whose definition it
   * fits and validates against. A payload may name the shape it means
   * with the DataSurfacePipelineInterface::SHAPE selector instead. The
   * shape's own constraints judge what was sent, then toCanonical(), then
   * the canonical's constraints judge the result. The conversion happens
   * in prepare, so a target only ever sees canonical values.
   *
   * Recorded now and attached at seal, so a contributor may add a shape
   * before the key's owner has declared it — subscribers run in module
   * order, not in ownership order. Sealing refuses:
   * - a key the surface does not declare, or one inside a mount or a
   *   slot, which is the child's to shape from its own build event;
   * - a key typed `any`, a list, or a secret;
   * - a top-level key another key refines against, because a refiner
   *   reads the value as it was sent;
   * - two readings of the key that one input could fit — the same data
   *   type family, or maps sharing a property — naming the two.
   *
   * @param string $key
   *   The surface key, dotted to reach into a map:
   *   `third_party_settings.my_module.my_setting` for a key another
   *   module mounted.
   * @param string $id
   *   The shape's id, unique on the key, never starting with "@". It is
   *   what a payload's selector and a form display name it by.
   * @param \Drupal\data_surface\DataSurfaceShapeInterface $shape
   *   The shape.
   * @param string|null $contributor
   *   The module contributing it, or NULL for the surface's owner.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the id is empty or starts with "@".
   * @throws \LogicException
   *   When the key already has a shape with that id, or the builder is
   *   sealed.
   *
   * @see docs/shapes.md
   */
  public function addShape(string $key, string $id, DataSurfaceShapeInterface $shape, ?string $contributor = NULL): static;

  /**
   * Fixes a nested surface at one key.
   *
   * The key's definition becomes a map whose property definitions are
   * the child's definitions, in the child's order. What the key holds is
   * the child's to say — its shape is known from the child's address, not
   * from any value — so the child is never restated and never refined
   * from the parent's frame:
   * - Refinement inside the mount is the child's own. When the parent is
   *   refined, the child is refined against the value at this key, and
   *   its refiners are dispatched under the child's own key names. No
   *   parent refiner ever matches into a child path.
   * - The child's contributors and filters already ran when it was
   *   built; mounting it does not run them again.
   * - Paths into the mount are dotted from the parent: a violation of
   *   the child's `columns` at `settings` is reported as
   *   `settings.columns`.
   * - The child's cacheability is merged into the parent's at seal.
   *
   * The child may be given three ways:
   * - A DataSurfaceCoordinate, preferred whenever the child is somebody
   *   else's surface: the factory resolves it at seal, the child's own
   *   build event runs, and the address is kept on the entry so whatever
   *   advertises the parent can name the child. A builder holding a
   *   coordinate can only be sealed by the factory.
   * - A sealed DataSurfaceInterface, for a caller already holding one.
   * - A callable taking a DataSurfaceBuilderInterface, for a small child
   *   that is part of its parent's own declaration — the same signature
   *   as declareDataSurface(), so `[Other::class, 'declareDataSurface']`
   *   works too. It is said into a fresh builder at seal, with no
   *   refiner bound, and it dispatches no build event of its own.
   *
   * A key may be described before it is mounted with an empty
   * MapDataDefinition carrying its label and description; that map is
   * kept as the shell the child's definitions are written into, and
   * getDefinition() hands it back until seal.
   *
   * @param string $key
   *   The surface key.
   * @param \Drupal\data_surface\DataSurfaceInterface|\Drupal\data_surface\DataSurfaceCoordinate|callable $child
   *   The child surface, its address, or a callable declaring it.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the key already holds a definition other than an empty map.
   * @throws \LogicException
   *   When the key is already a mount, or the builder is sealed.
   *
   * @see docs/nesting.md
   */
  public function mount(string $key, DataSurfaceInterface|DataSurfaceCoordinate|callable $child): static;

  /**
   * Declares a key whose shape a sibling chooses, out of a fixed set.
   *
   * The shape-versus-values rule made usable: refinement may only narrow
   * values, so when a key's *shape* depends on another key's answer, the
   * shape is declared statically as a union instead. Statically the
   * surface advertises all of it:
   * - The discriminator: `$by` gains a Choice over the variant ids. If it
   *   already carries a list of allowed values the variant ids must all
   *   be in it, and the list is narrowed to them; at seal the two must
   *   agree exactly, so a contributor adding a value without a variant
   *   is refused there.
   * - Every variant's shape, on the entry's SurfaceSlot.
   * - A placeholder definition for the key itself, typed `any` and
   *   marked with DefinitionMetadata::setSlot().
   *
   * The key refines against `$by`, so with a value for the discriminator
   * the key's definition is exactly that variant's map, and a generated
   * form rebuilds the slot over AJAX when the discriminator changes.
   * Values are held to the chosen variant: the pipeline hands the slot's
   * value to that variant's child, and a payload shaped for another
   * variant is refused on the slot, by path.
   *
   * @param string $key
   *   The slot's surface key.
   * @param string $by
   *   The sibling key whose value chooses the variant. It must already
   *   be declared, and hold a single value.
   * @param array<string, \Drupal\data_surface\DataSurfaceInterface|\Drupal\data_surface\DataSurfaceCoordinate|callable> $variants
   *   One child per allowed value of `$by`, keyed by that value, each
   *   given any way mount() takes one.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When there are no variants, a variant is not a child, the
   *   discriminator is the key itself, unknown, or not a single value.
   * @throws \LogicException
   *   When a variant names a value the discriminator does not allow, the
   *   key is already a mount, or the builder is sealed.
   *
   * @see docs/nesting.md
   */
  public function mountVariants(string $key, string $by, array $variants): static;

  /**
   * Gets an output definition, so alters can inspect or modify it.
   *
   * @param string $name
   *   The output key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when the builder holds no such output.
   */
  public function getOutputDefinition(string $name): ?DataDefinitionInterface;

  /**
   * Adds or replaces an output definition.
   *
   * What the host's execution emits, declared in the same vocabulary as
   * what it accepts. Provider/host territory, exactly as setDefinition()
   * is: a third party adding an output of its own mounts it under its
   * namespace with setThirdPartyOutputDefinition() instead, and never
   * replaces one the owner declared — a consumer reading the owner's
   * output schema has to keep reading true.
   *
   * Two differences from an input definition, both enforced:
   * - An output carries no default. A default is what a value starts
   *   from when nobody sent one, and nobody sends an output; a key the
   *   producer has nothing to say about is absent, which is what the
   *   Omitted sentinel spells. A definition carrying 'default_value',
   *   at any depth, is refused.
   * - An output cannot be locked. Locking narrows what a caller may
   *   send, and nothing sends an output.
   *
   * @param string $name
   *   The output key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition describing what is emitted under that key.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the definition declares a default value, or when the name is
   *   the reserved third-party outputs key.
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function setOutputDefinition(string $name, DataDefinitionInterface $definition): static;

  /**
   * Mounts a third-party output under the provider's namespace.
   *
   * The output side's mirror of setThirdPartyDefinition(): a contributor
   * may *add* to what a host emits, under its own provider namespace, so
   * the emitted value lands at
   * third_party_outputs.<provider>.<key> — advertised, conformance
   * checked, and machine-visible. What it may never do is alter the
   * owner's outputs, which is why there is a namespace at all.
   *
   * @param string $provider
   *   The module mounting the output.
   * @param string $key
   *   The output's key within that provider's namespace.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition describing what is emitted.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the provider id is empty or claims the owner's name, or the
   *   definition declares a default value.
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function setThirdPartyOutputDefinition(string $provider, string $key, DataDefinitionInterface $definition): static;

  /**
   * Declares that a target refines against sibling values.
   *
   * @param string $target
   *   The surface key whose definition is refined.
   * @param array $dependencies
   *   The sibling keys it is refined against.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function addRefinement(string $target, array $dependencies): static;

  /**
   * Declares that an output refines against input values.
   *
   * The dependencies are *input* keys: what a host emits is decided by
   * what it was given, and an output never refines against another
   * output, because outputs are produced in one act by code that
   * already knows all of them.
   *
   * @param string $output
   *   The output key whose definition is refined.
   * @param array $dependencies
   *   The input keys it is refined against.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function addOutputRefinement(string $output, array $dependencies): static;

  /**
   * Appends a refiner to an output's chain.
   *
   * Links run in registration order, the owner's first, each receiving
   * the previous link's output, and every link is held to the narrowing
   * contract against what it was handed.
   *
   * There is no option-space division here, unlike the input side: an
   * output's value space has exactly one owner, because a contributor
   * cannot extend an output's choices — it mounts an output of its own
   * instead. So there is nothing to divide and nothing to take a union
   * of, and a chain is a chain.
   *
   * @param string $output
   *   The output key whose definition is refined.
   * @param \Drupal\data_surface\DataSurfaceOutputRefinerInterface $refiner
   *   The refiner to append.
   * @param string|null $contributor
   *   The provider this refiner speaks for, or NULL for the owner.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function addOutputRefiner(string $output, DataSurfaceOutputRefinerInterface $refiner, ?string $contributor = NULL): static;

  /**
   * Appends a refiner to one contribution's chain for a target.
   *
   * A chain narrows one contribution's own slice of the target: the
   * owner's refiners are handed the values the owner declared, and a
   * contributor's are handed the values that contributor added with
   * extendChoices(). Links run in registration order, each receiving the
   * previous link's output, and every link is held to the narrowing
   * contract against what it was handed — so a contributor can neither
   * narrow away somebody else's value nor hand back one of its own that
   * it was not given.
   *
   * Refiners must be serializable (services or named classes, no
   * closures or anonymous classes): surfaces ride along in cached forms.
   *
   * @param string $target
   *   The surface key whose definition is refined.
   * @param \Drupal\data_surface\DataSurfaceRefinerInterface $refiner
   *   The refiner to append.
   * @param string|null $contributor
   *   The provider whose contribution this refiner speaks for, or NULL
   *   for the surface's owner.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function addRefiner(string $target, DataSurfaceRefinerInterface $refiner, ?string $contributor = NULL): static;

  /**
   * Registers a policy filter, which runs last and may only remove.
   *
   * The third role: a filter speaks for the site rather than for a
   * contribution, so it sees every key after every contribution has been
   * merged in, and is held to remove-only against what it was handed. Use
   * it for "this installation does not allow that", never for "my module
   * prefers".
   *
   * @param \Drupal\data_surface\DataSurfaceFilterInterface $filter
   *   The filter to register.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function addFilter(DataSurfaceFilterInterface $filter): static;

  /**
   * Declares what the surface being built depends on.
   *
   * A surface is computed from live site state, so it can only be reused
   * for as long as that state holds. Whatever is declared here is
   * carried by the sealed surface, merged with what every refiner that
   * runs declares, and read by anything that renders or stores the
   * surface. The split between metadata that travels into a shared
   * cache and metadata that only holds for one request is in
   * docs/refinement.md.
   *
   * @param \Drupal\Core\Cache\CacheableDependencyInterface $dependency
   *   The dependency, which may be a CacheableMetadata built by hand.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  public function addCacheableDependency(CacheableDependencyInterface $dependency): static;

  /**
   * Returns whether the builder has been sealed.
   *
   * @return bool
   *   TRUE once seal() has run, after which every mutator throws.
   */
  public function isSealed(): bool;

  /**
   * Seals the builder into an immutable surface.
   *
   * The only construction path for a surface. Idempotent: the same
   * surface comes back however often this is called, so a caller holding
   * a builder and a caller holding its surface are looking at one
   * object.
   *
   * This is also where the refinement map is checked for cycles. It is
   * asked here and not earlier because the map is only whole once every
   * contributor has added to it, and a key that refines against itself
   * would otherwise produce an answer that depends on the order the keys
   * happened to be read in.
   *
   * What comes out is a DefinitionMap: the builder's own bookkeeping —
   * definitions, refinement edges, locked keys, contributed values and
   * refiner chains — folded into one entry per key, in declaration
   * order, and checked once for the rest of its shape.
   *
   * The outputs become a second map of the same type, holding whatever
   * the host declared it emits. Its entries carry no locked flag and no
   * contributed values, and their dependency edges name *input* keys,
   * which is checked here against the input map: an output refining
   * against something the surface never accepts could not be refined at
   * all.
   *
   * Mounted children are sealed here too: a coordinate is resolved
   * through the factory handed in, an inline declaration is sealed into a
   * child of its own, and each child's cacheability joins the parent's.
   *
   * @param \Drupal\data_surface\DataSurfaceFactoryInterface|null $factory
   *   The factory sealing this builder, which resolves any coordinate a
   *   mount names. Passed by DataSurfaceFactoryInterface::build(); a
   *   builder sealed by hand passes nothing and may hold no coordinate.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The advertised surface.
   *
   * @throws \LogicException
   *   When a key refines, directly or indirectly, against itself, or a
   *   mount cannot be sealed as declared.
   * @throws \InvalidArgumentException
   *   When an output refinement names an output, or an input key the
   *   surface does not declare.
   */
  public function seal(?DataSurfaceFactoryInterface $factory = NULL): DataSurfaceInterface;

}
