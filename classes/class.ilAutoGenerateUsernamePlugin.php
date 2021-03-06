<?php
/* Copyright (c) 1998-2009 ILIAS open source, Extended GPL, see docs/LICENSE */

require_once 'Services/EventHandling/classes/class.ilEventHookPlugin.php';
require_once 'Services/Component/classes/class.ilPluginAdmin.php';

/**
 * @author Fabian Wolf <wolf@leifos.com>
 */
class ilAutoGenerateUsernamePlugin extends ilEventHookPlugin
{
	/**
	 * @var ilAutoGenerateUsernameConfig
	 */
	protected $settings = null;

	/**
	 * @return string
	 */
	final public function getPluginName()
	{
		return "AutoGenerateUsername";
	}

	public function handleEvent($a_component, $a_event, $a_params)
	{
		switch($a_component)
		{
			case 'Services/User':
				switch($a_event)
				{
					case 'afterCreate':
						//TODO: Add if wehen context is ready
						//if((isset($a_params['context']) && $this->getSettings()->isValidContext($a_params['context']))
						//{
							/**
							 * @var ilObjUser $user_obj
							 */
							$user_obj = $a_params['user_obj'];

							if(is_object($user_obj) && strtolower(get_class($user_obj)) == "ilobjuser")
							{
								$user_obj->updateLogin($this->generateUsername($user_obj));
							}
						//}
					break;
				}
			break;
		}

		return true;
	}

	/**
	 * @param ilObjUser $a_usr
	 * @param bool $a_demo
	 * @return string
	 */
	public function generateUsername($a_usr, $a_demo = false)
	{
		$settings = $this->getSettings();

		$template = $settings->getLoginTemplate();
		$map = $this->getMap($a_usr);


		while(strpos($template, '[') !== false && strpos($template, ']') !== false)
		{
			$start = strpos($template, '[');
			$end = strpos($template, ']');
			$expression = substr($template, $start, $end-$start+1);
			$length = 0;
			$replacement = "";

			if(strpos($expression, ":"))
			{
				$length = substr($expression,
					strpos($expression, ":")+1 ,
					strpos($expression, ']')-strpos($expression, ":")-1);

				$var = substr($expression, 1,strpos($expression, ':')-1);
			}
			else
			{
				$var = substr($expression, 1,strpos($expression, ']')-1);
			}

			if($var == "number")
			{
				if($a_demo)
				{
					$replacement = $settings->getIdSequenz();
				}
				else
				{
					$replacement = $settings->getNextId();
				}

			}
			elseif($var == "hash")
			{
				$replacement = strrev(uniqid());
			}
			elseif(in_array($var, array_keys($map)))
			{
				$replacement = $map[$var];
			}

			if($length > 0 && $var == "number")
			{
				while(strlen($replacement) < $length)
				{
					$replacement = 0 . $replacement;
				}
			}
			elseif($length > 0 && $var != "number")
			{
				$replacement = substr($replacement, 0, $length);
			}

			$replacement = $this->validateString(
				$replacement,
				$settings->getStringToLower(),
				$settings->getUseCamelCase(),
				true);

			$template = str_replace($expression,$replacement, $template);
		}
		//validate to login
		$template = $this->validateLogin($template);
		$ret = $template;

		$count = 1;
		while(ilObjUser::_loginExists($ret))
		{
			$ret = $template . '_' . $count;
			$count = $count+1;
		}

		return $ret;
	}

	protected function getMap($a_user)
	{
		return array_merge($this->getUserMap($a_user), $this->getUDFMap($a_user));
	}

	/**
	 * @param ilObjUser $a_user
	 *
	 * @return array
	 */
	protected function getUserMap($a_user)
	{
		return array(
			"login" => $a_user->getLogin(),
			"firstname" => $a_user->getFirstname(),
			"lastname" => $a_user->getLastname(),
			"email" => $a_user->getEmail(),
			"matriculation" => $a_user->getMatriculation()
		);
	}

	/**
	 * @param ilObjUser$a_user
	 * @return array
	 */
	protected function getUDFMap($a_user)
	{
		$map = array();
		include_once './Services/User/classes/class.ilUserDefinedFields.php';
		/**
		 * @var ilUserDefinedFields $user_defined_fields
		 */
		$user_defined_fields = ilUserDefinedFields::_getInstance();
		$user_defined_data = $a_user->getUserDefinedData();
		foreach($user_defined_fields->getDefinitions() as $field_id => $definition)
		{
			if($definition['field_type'] !=  UDF_TYPE_WYSIWYG)
			{
				$map["udf_".$field_id] = $user_defined_data["f_".$field_id];
			}
		}

		return $map;
	}

	/**
	 * @param string $string
	 * @return string
	 */
	public function camelCase($string)
	{
		if(strpos($string, ' ') !== false)
		{
			return $string = str_replace(' ', '', ucwords($string));
		}

		return $string;
	}

	/**
	 * @param string $a_string
	 * @param bool $a_str_to_lower
	 * @param bool $a_camel_case
	 * @param bool $a_umlauts
	 * @return string
	 */
	public function validateString($a_string, $a_str_to_lower = false, $a_camel_case = false ,$a_umlauts = false)
	{
		if($a_str_to_lower)
		{
			$a_string = strtolower($a_string);
		}

		if($a_camel_case)
		{
			$a_string = $this->camelCase($a_string);
		}
		else
		{
			$a_string = str_replace(' ', '', $a_string);
		}

		if($a_umlauts)
		{
			$a_string = iconv("utf-8","ASCII//TRANSLIT",$a_string);
		}

		return $a_string;
	}

	/**
	 * @return ilAutoGenerateUsernameConfig
	 */
	protected function getSettings()
	{
		if(!$this->settings)
		{
			$this->includeClass("class.ilAutoGenerateUsernameConfig.php");
			$this->settings = new ilAutoGenerateUsernameConfig();
		}

		return $this->settings;
	}

	protected function validateLogin($a_login)
	{
		$login = str_split($a_login);
		$search = '^[A-Za-z0-9_\.\+\*\@!\$\%\~\-]+$';
		$a_login = "";

		foreach($login as $char)
		{
			if(ereg($search, $char))
			{
				$a_login .= $char;
			}
		}

		if(empty($a_login) || strlen($a_login) < 3)
		{
			return 'invalid_login';
		}
		return $a_login;
	}
}
