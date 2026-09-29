<?php
/**
 * @license MIT
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\Laravel\SerializableClosure;

use Closure;
use DavMlm\Vendor\Laravel\SerializableClosure\Exceptions\InvalidSignatureException;
use DavMlm\Vendor\Laravel\SerializableClosure\Serializers\Signed;
use DavMlm\Vendor\Laravel\SerializableClosure\Signers\Hmac;

class SerializableClosure
{
    /**
     * The closure's serializable.
     *
     * @var \DavMlm\Vendor\Laravel\SerializableClosure\Contracts\Serializable
     */
    protected $serializable;

    /**
     * Creates a new serializable closure instance.
     *
     * @param  \Closure  $closure
     * @return void
     */
    public function __construct(Closure $closure)
    {
        $this->serializable = Serializers\Signed::$signer
            ? new Serializers\Signed($closure)
            : new Serializers\Native($closure);
    }

    /**
     * Resolve the closure with the given arguments.
     *
     * @return mixed
     */
    public function __invoke()
    {
        return call_user_func_array($this->serializable, func_get_args());
    }

    /**
     * Gets the closure.
     *
     * @return \Closure
     */
    public function getClosure()
    {
        return $this->serializable->getClosure();
    }

    /**
     * Create a new unsigned serializable closure instance.
     *
     * @param  Closure  $closure
     * @return \DavMlm\Vendor\Laravel\SerializableClosure\UnsignedSerializableClosure
     */
    public static function unsigned(Closure $closure)
    {
        return new UnsignedSerializableClosure($closure);
    }

    /**
     * Sets the serializable closure secret key.
     *
     * @param  string|null  $secret
     * @return void
     */
    public static function setSecretKey($secret)
    {
        Serializers\Signed::$signer = $secret
            ? new Hmac($secret)
            : null;
    }

    /**
     * Sets the transformer that should be used when serializing use variables.
     *
     * @param  \Closure|null  $transformer
     * @return void
     */
    public static function transformUseVariablesUsing($transformer)
    {
        Serializers\Native::$transformUseVariables = $transformer;
    }

    /**
     * Sets the resolver that should be used when unserializing use variables.
     *
     * @param  \Closure|null  $resolver
     * @return void
     */
    public static function resolveUseVariablesUsing($resolver)
    {
        Serializers\Native::$resolveUseVariables = $resolver;
    }

    /**
     * Get the serializable representation of the closure.
     *
     * @return array{serializable: \DavMlm\Vendor\Laravel\SerializableClosure\Serializers\Signed|\DavMlm\Vendor\Laravel\SerializableClosure\Contracts\Serializable}
     */
    public function __serialize()
    {
        return [
            'serializable' => $this->serializable,
        ];
    }

    /**
     * Restore the closure after serialization.
     *
     * @param  array{serializable: \DavMlm\Vendor\Laravel\SerializableClosure\Serializers\Signed|\DavMlm\Vendor\Laravel\SerializableClosure\Contracts\Serializable}  $data
     * @return void
     *
     * @throws \DavMlm\Vendor\Laravel\SerializableClosure\Exceptions\InvalidSignatureException
     */
    public function __unserialize($data)
    {
        if (Signed::$signer && ! $data['serializable'] instanceof Signed) {
            throw new InvalidSignatureException();
        }

        $this->serializable = $data['serializable'];
    }
}
