<?php

namespace PtWeChatPay;

/**
 * Chainable points the client interface for sending HTTP requests.
 */
trait BuilderTrait
{
    abstract public function getDriver();

    /**
     * URI pathname
     *
     * @param string $seperator - The URI seperator, default is slash(`/`) character
     *
     * @return string - The URI string
     */
    abstract protected function pathname($seperator = '/');

    /**
     * @inheritDoc
     */
    public function get(array $options = array())
    {
        return $this->getDriver()->request('GET', $this->pathname(), $options);
    }

    /**
     * @inheritDoc
     */
    public function put(array $options = array())
    {
        return $this->getDriver()->request('PUT', $this->pathname(), $options);
    }

    /**
     * @inheritDoc
     */
    public function post(array $options = array())
    {
        return $this->getDriver()->request('POST', $this->pathname(), $options);
    }

    /**
     * @inheritDoc
     */
    public function patch(array $options = array())
    {
        return $this->getDriver()->request('PATCH', $this->pathname(), $options);
    }

    /**
     * @inheritDoc
     */
    public function delete(array $options = array())
    {
        return $this->getDriver()->request('DELETE', $this->pathname(), $options);
    }

    /**
     * @inheritDoc
     */
    public function getAsync(array $options = array())
    {
        return $this->getDriver()->requestAsync('GET', $this->pathname(), $options);
    }

    /**
     * @inheritDoc
     */
    public function putAsync(array $options = array())
    {
        return $this->getDriver()->requestAsync('PUT', $this->pathname(), $options);
    }

    /**
     * @inheritDoc
     */
    public function postAsync(array $options = array())
    {
        return $this->getDriver()->requestAsync('POST', $this->pathname(), $options);
    }

    /**
     * @inheritDoc
     */
    public function patchAsync(array $options = array())
    {
        return $this->getDriver()->requestAsync('PATCH', $this->pathname(), $options);
    }

    /**
     * @inheritDoc
     */
    public function deleteAsync(array $options = array())
    {
        return $this->getDriver()->requestAsync('DELETE', $this->pathname(), $options);
    }
}
