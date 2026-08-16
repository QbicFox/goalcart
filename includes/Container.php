<?php
/**
 * Dependency injection container for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart;

defined( 'ABSPATH' ) || exit;

/**
 * Class Container
 *
 * A lightweight dependency injection container. Services are bound to
 * factory closures and resolved lazily. Bindings can be shared (singleton)
 * or resolved fresh on every request, and classes with resolvable
 * constructor dependencies can be autowired via reflection.
 *
 * Mirrors the reference plugin (WooInsights\Container) exactly.
 */
class Container {

	/**
	 * Registered bindings: id => factory callable.
	 *
	 * @var array<string, callable>
	 */
	protected $bindings = array();

	/**
	 * Shared flags: id => bool.
	 *
	 * @var array<string, bool>
	 */
	protected $shared = array();

	/**
	 * Resolved singleton instances: id => object.
	 *
	 * @var array<string, object>
	 */
	protected $instances = array();

	/**
	 * Register a binding.
	 *
	 * @param string   $id      Identifier (usually a class name).
	 * @param callable $factory Factory callable that receives this container.
	 * @param bool     $shared  Whether to cache the resolved instance.
	 * @return $this
	 */
	public function set( $id, $factory, $shared = true ) {
		$this->bindings[ $id ] = $factory;
		$this->shared[ $id ]   = (bool) $shared;
		unset( $this->instances[ $id ] );

		return $this;
	}

	/**
	 * Register a shared (singleton) binding.
	 *
	 * @param string   $id      Identifier.
	 * @param callable $factory Factory callable.
	 * @return $this
	 */
	public function singleton( $id, $factory ) {
		return $this->set( $id, $factory, true );
	}

	/**
	 * Register a non-shared binding (fresh instance per resolution).
	 *
	 * @param string   $id      Identifier.
	 * @param callable $factory Factory callable.
	 * @return $this
	 */
	public function factory( $id, $factory ) {
		return $this->set( $id, $factory, false );
	}

	/**
	 * Bind an id to a concrete class name using autowiring.
	 *
	 * @param string $id       Identifier.
	 * @param string $concrete Concrete class to instantiate.
	 * @return $this
	 */
	public function bind( $id, $concrete ) {
		return $this->set(
			$id,
			function () use ( $concrete ) {
				return $this->make( $concrete );
			}
		);
	}

	/**
	 * Resolve an identifier to an instance.
	 *
	 * Falls back to autowiring when the id is a class name with no binding.
	 *
	 * @param string $id Identifier.
	 * @return mixed
	 * @throws \InvalidArgumentException When the id cannot be resolved.
	 */
	public function get( $id ) {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->bindings[ $id ] ) ) {
			if ( class_exists( $id ) ) {
				return $this->make( $id );
			}

			throw new \InvalidArgumentException( sprintf( 'No binding registered for "%s".', $id ) );
		}

		$instance = call_user_func( $this->bindings[ $id ], $this );

		if ( ! empty( $this->shared[ $id ] ) ) {
			$this->instances[ $id ] = $instance;
		}

		return $instance;
	}

	/**
	 * Whether the given id can be resolved.
	 *
	 * Mirrors get(): returns true for registered bindings, resolved
	 * instances, and any class name that could be autowired.
	 *
	 * @param string $id Identifier.
	 * @return bool
	 */
	public function has( $id ) {
		return isset( $this->bindings[ $id ] )
			|| isset( $this->instances[ $id ] )
			|| class_exists( $id );
	}

	/**
	 * Automatically construct a class, resolving constructor dependencies
	 * from the container.
	 *
	 * @param string $class Class name.
	 * @return object
	 * @throws \InvalidArgumentException When a dependency cannot be resolved.
	 */
	public function make( $class ) {
		$reflector = new \ReflectionClass( $class );

		if ( ! $reflector->isInstantiable() ) {
			throw new \InvalidArgumentException( sprintf( 'Class "%s" is not instantiable.', $class ) );
		}

		$constructor = $reflector->getConstructor();

		if ( null === $constructor ) {
			return new $class();
		}

		$dependencies = array();

		foreach ( $constructor->getParameters() as $parameter ) {
			$type = $parameter->getType();

			if ( $type instanceof \ReflectionNamedType && ! $type->isBuiltin() ) {
				$dependencies[] = $this->get( $type->getName() );
			} elseif ( $parameter->isDefaultValueAvailable() ) {
				$dependencies[] = $parameter->getDefaultValue();
			} else {
				throw new \InvalidArgumentException(
					sprintf( 'Cannot resolve parameter "$%s" for class "%s".', $parameter->getName(), $class )
				);
			}
		}

		return $reflector->newInstanceArgs( $dependencies );
	}
}
