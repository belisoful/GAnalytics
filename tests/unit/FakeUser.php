<?php

namespace belisoful\GAnalytics\Test\Unit;

use Prado\Security\IUser;

/** An application user with a name and a guest flag, for the user id derivation. */
class FakeUser extends \Prado\TComponent implements IUser
{
	public function __construct(private string $_name = '', private bool $_isGuest = true)
	{
		parent::__construct();
	}

	public function getName()
	{
		return $this->_name;
	}

	public function setName($value)
	{
		$this->_name = (string) $value;
	}

	public function getIsGuest()
	{
		return $this->_isGuest;
	}

	public function setIsGuest($value)
	{
		$this->_isGuest = (bool) $value;
	}

	public function getRoles()
	{
		return [];
	}

	public function setRoles($value)
	{
	}

	public function isInRole($role)
	{
		return false;
	}

	public function saveToString()
	{
		return \serialize([$this->_name, $this->_isGuest]);
	}

	public function loadFromString($string)
	{
		[$this->_name, $this->_isGuest] = \unserialize($string);
		return $this;
	}
}
