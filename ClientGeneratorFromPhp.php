<?php

abstract class ClientGeneratorFromPhp 
{
	protected $_files = array();
	protected $_services = array();
	protected $_types = array();
	protected $_includeList = array();
	protected $_sourcePath = "";
	protected $_typesToIgnore = array();
	protected $_classMap = null;
	protected $_excludePathList = array();
	
	protected $package = 'Kaltura';
	protected $subpackage = 'Client';
	
	public function setPackage($package)
	{
		$this->package = $package;
	}

	public function setSubpackage($subpackage)
	{
		$this->subpackage = $subpackage;
	}

	/**
	 * @return the $_services
	 */
	public function getServices() {
		return $this->_services;
	}

	/**
	 * @return the $_types
	 */
	public function getTypes() {
		return $this->_types;
	}

	/**
	 * @return the $_includeList
	 */
	public function getIncludeList() {
		return $this->_includeList;
	}

	public function ClientGeneratorFromPhp($sourcePath = null) 
	{
		$this->_sourcePath = realpath($sourcePath);
		
		if (($sourcePath !== null) && !(is_dir($sourcePath)))
			throw new Exception("Source path was not found [$sourcePath]");
		
		if (is_dir($sourcePath))
			$this->addSourceFiles($this->_sourcePath);	
	}
	
	public function getOutputFiles()
	{
		return $this->_files;
	}
	
	protected function addFile($fileName, $fileContents)
	{
		 $this->_files[$fileName] = $fileContents;
	}
	
	protected function addSourceFiles($directory)
	{
		// add if file
		if (is_file($directory)) 
		{
			$file = str_replace($this->_sourcePath.DIRECTORY_SEPARATOR, "", $directory);
			$this->addFile($file, file_get_contents($directory));
			return;
		}
		
		// loop through the folder
		$dir = dir($directory);
		while (false !== $entry = $dir->read()) 
		{
			// skip pointers & hidden files
			if ($this->beginsWith($entry, ".")) 
			{
				continue;
			}
			 
			$this->addSourceFiles(realpath("$directory/$entry"));
		}
		 
		// clean up
		$dir->close();
	}
	
	/**
	 * Main generation method, can be overload to support a different flow
	 *
	 */
	public function generate() 
	{		
		$this->load();
		
		$this->writeHeader();

		$this->writeBeforeTypes();
		// types
		foreach($this->_types as $typeReflector)
		{
			$this->writeType($typeReflector);
		}
		$this->writeAfterTypes();
		
		$this->writeBeforeServices();
		// services
		foreach($this->_services as $serviceId => $serviceActionItem)
		{
			/* @var $serviceActionItem KalturaServiceActionItem */
			$this->writeBeforeService($serviceActionItem);
			$serviceName = $serviceActionItem->serviceInfo->serviceName;
			$serviceId = $serviceActionItem->serviceId;
			$actions = $serviceActionItem->actionMap;
			foreach($actions as $action => $actionCallback)
			{
			    $actionReflector = new KalturaActionReflector($serviceId, $action, $actionCallback);
				$actionInfo = $actionReflector->getActionInfo();
				
				if($actionInfo->serverOnly)
					continue;
					
				if (strpos($actionInfo->clientgenerator, "ignore") !== false)
					continue;
					
				$outputTypeReflector = $action->getActionOutputType();
				$actionParams = $action->getActionParams();
				$this->writeServiceAction($serviceId, $serviceName, $action, $actionParams, $outputTypeReflector);				
			}
			$this->writeAfterService($serviceActionItem);
		}
		$this->writeAfterServices();
		
		$this->writeFooter();
	}
	
	protected function initClassMap()
	{
		if ($this->_classMap !== null)
			return;
		
		$classMapFileLocation = KAutoloader::getClassMapFilePath();		
		$this->_classMap = unserialize(file_get_contents($classMapFileLocation));
	}
	
	public function load()
	{
		$this->loadServicesInfo();
		
		// load the filter order by string enums
		$filterOrderByStringEnums = array();
		foreach($this->_types as $typeReflector)
		{
			if (strpos($typeReflector->getType(), "Filter", strlen($typeReflector->getType()) - 6))
			{
				$filterOrderByStringEnumTypeName = str_replace("Filter", "OrderBy", $typeReflector->getType());
				if (class_exists($filterOrderByStringEnumTypeName))
					$filterOrderByStringEnums[] = KalturaTypeReflectorCacher::get($filterOrderByStringEnumTypeName);
			}
		}
		$this->_types = array_merge($this->_types, $filterOrderByStringEnums);
		
		// organize types so enums will be first
		$enumTypes = array();
		$classTypes = array();
	    foreach($this->_types as $typeReflector)
		{
			if ($typeReflector->isEnum() || $typeReflector->isStringEnum())
			    $enumTypes[$typeReflector->getType()] = $typeReflector;
		    else
		        $classTypes[$typeReflector->getType()] = $typeReflector; 
		};
		
		// sort by type name
		ksort($enumTypes);

		$this->sortClassTypes($classTypes);
		
		// merge back
		$this->_types = array_merge($enumTypes, $classTypes);
	}
	
	/**
	 * Called to write the header
	 *
	 */
	protected abstract function writeHeader();
	
	/**
	 * Called to write the footer
	 *
	 */
	protected abstract function writeFooter();
	
	protected abstract function writeBeforeServices();
	
	protected abstract function writeBeforeService(KalturaServiceActionItem $serviceReflector);
	
	/**
	 * Called while looping the actions inside a service to write the service action description
	 *
	 * @param string $serviceName
	 * @param string $action
	 * @param array $actionParams
	 * @param KalturaTypeReflector $outputTypeReflector
	 */
	protected abstract function writeServiceAction($serviceId, $serviceName, $action, $actionParams, $outputTypeReflector);
	
	protected abstract function writeAfterService(KalturaServiceActionItem $serviceReflector);
	
	protected abstract function writeAfterServices();
	
	protected abstract function writeBeforeTypes();
	
	/**
	 * Called to write the description of a type
	 * 
	 * @param array $typeDescription 
	 */
	protected abstract function writeType(KalturaTypeReflector $type);
	
	protected abstract function writeAfterTypes();
	

	/**
	 * Scans the file system and loads the description of the services to an array
	 *
	 */
	protected function loadServicesInfo() 
	{
		$this->initClassMap();
		
		$serviceMap = KalturaServicesMap::getMap();
		foreach($serviceMap as $serviceId => $serviceActionItem)
		{
		    /* @var $serviceActionItem KalturaServiceActionItem */
		    $actionsToInclude = array();
		    if ( count($this->_includeList) && !array_key_exists($serviceId, $this->_includeList) )
  			{
  			    continue;
  			}
  			$actionsToInclude = $this->_includeList[$serviceId];
  			$serviceActionItemToAdd = KalturaServiceActionItem::cloneItem($serviceActionItem);
		    foreach ($serviceActionItem->actionMap as $actionId => $actionCallback)
		    {
		        list ( $serviceClassName, $actionMethodName) = array_values($actionCallback);
		        
		        //Check if the service path for the current action is excluded
		        $servicePath = $this->_classMap[$serviceClassName];	
    		    if ($this->isPathExcluded($servicePath))
      			{
      			    unset($serviceActionItemToAdd->actionMap[$actionId]);
      			    continue;
      			}
      			
      			// check if the current action is included
      			if (count($actionsToInclude) && !array_key_exists(strtolower($actionId), $actionsToInclude))
      			{
      			    unset($serviceActionItemToAdd->actionMap[$actionId]); 
      			    continue;
      			}
      			
      			$actionReflector = new KalturaActionReflector($serviceId, $actionId, $actionCallback);
      			if (!$this->shouldUseServiceAction($actionReflector))
      			{
      			    continue;
      			}
      			
      			$serviceActionItemToAdd->actionMap[$actionId] = $actionReflector;
      			
      			$actionParams = $actionReflector->getActionParams();
      			foreach ($actionParams as $actionParam)
      			{
      			    if ($actionParam->isComplexType())
					{
						$typeReflector = KalturaTypeReflectorCacher::get($actionParam->getType());
						if(!$typeReflector)
							throw new Exception("Couldn't load type reflector for service [$serviceId] action [$actionId] param type[" . $actionParam->getType() . "]");
						
						$this->loadTypesRecursive($typeReflector);
					}
      			}
      			
		        $outputInfo = $actionReflector->getActionOutputType();
				if ($outputInfo && $outputInfo->isComplexType())
				{
					$typeReflector = $outputInfo->getTypeReflector();
					if(!$typeReflector)
						throw new Exception("Couldn't load type reflector for service [$serviceId] action [$actionId] output type[" . $outputInfo->getType() . "]");
						
					$this->loadTypesRecursive($typeReflector);
				}
		    }
		    
		    if ( count($serviceActionItem->actionMap) )
		    {
		        $this->_services[$serviceId] = $serviceActionItemToAdd;
		    }
		}
		
//		// load the child types for all the types that we found except for types that inherit KalturaFilter
//
//		foreach($this->_types as $type => $typeReflector)
//		{
//			$reflector = new ReflectionClass($typeReflector->getType());
//			
//			if (!$typeReflector->isEnum() && !$typeReflector->isStringEnum() && !$reflector->isSubclassOf("KalturaFilter"))
//			{
//				$this->loadChildTypes($typeReflector);
//			}
//		}
	}
	
	private function loadTypesRecursive(KalturaTypeReflector $typeReflector)
	{
		if(in_array($typeReflector->getType(), $this->_typesToIgnore))
			return;

		$this->initClassMap();
		if ($this->isPathExcluded($this->_classMap[$typeReflector->getType()]))
			return;
			
	    $parentTypeReflector = $typeReflector->getParentTypeReflector();
	    if ($parentTypeReflector)
	    {
	        $parentType = $parentTypeReflector->getType();
            $this->loadTypesRecursive($parentTypeReflector);   
	    }
	    
		$properties = $typeReflector->getProperties();
		foreach($properties as $property)
		{
			$subTypeReflector = $property->getTypeReflector();
			if ($subTypeReflector)
				$this->loadTypesRecursive($subTypeReflector);
		}
		
		if ($typeReflector->isArray())
		{
			$arrayTypeReflector = KalturaTypeReflectorCacher::get($typeReflector->getArrayType());
			if($arrayTypeReflector)
				$this->loadTypesRecursive($arrayTypeReflector);
		}
		
		$this->loadChildTypes($typeReflector);
	}
	
	private function loadChildTypes(KalturaTypeReflector $typeReflector)
	{
		if (isset($this->_types[$typeReflector->getType()]))
			return;
			
		$this->addType($typeReflector);
		
		$this->initClassMap();
		foreach($this->_classMap as $class => $path)
		{
			if (strpos($class, 'Kaltura') === 0 && strpos($class, '_') === false && strpos($path, 'api') !== false) // make sure the class is api object
			{
				$reflector = new ReflectionClass($class);
				if ($reflector->isSubclassOf('KalturaObject') && $reflector->isSubclassOf($typeReflector->getType()))
				{
					$classTypeReflector = KalturaTypeReflectorCacher::get($class);
					if($classTypeReflector)
						$this->loadTypesRecursive($classTypeReflector);
				}
			}
		}
	}
	
	protected function shouldUseServiceAction (KalturaActionReflector $actionReflector)
	{
	    $serviceId = $actionReflector->getServiceId();
	    
	    if ($actionReflector->getActionClassInfo()->serverOnly)
	    {
	        KalturaLog::info("Service [".$serviceId."] is server only");
	        return false;
	    }
	    
	    $actionId = $actionReflector->getActionId();
	    if (strpos($actionReflector->getActionInfo()->clientgenerator, "ignore") !== false)
	    {
	        KalturaLog::info("Action [$actionId] in service [$serviceId] ignored by generator");
	        return false;
	    }
	    
	    if (isset($this->_serviceActions[$serviceId]) && isset($this->_serviceActions[$serviceId][$actionId]))
	    {
	        KalturaLog::err("Service [$serviceId] action [$actionId] already exists!");
	        return false;
	    }
        
	    return true;
	}
	
	
	protected function addType(KalturaTypeReflector $objectReflector)
	{
		$type = $objectReflector->getType();
		
		if($objectReflector->isServerOnly())
		{
			KalturaLog::info("Type is server only [$type]");
			return;
		}
			
		if (!array_key_exists($type, $this->_types))
			$this->_types[$type] = $objectReflector;
	}
	
	public function isPathExcluded($path)
	{
		$path = realpath($path);
		foreach ($this->_excludePathList as $exclude)
		{
			if ($this->beginsWith($path, $exclude))
			{
				return true;
			}
		}
		return false;
	}
	
	public function setIncludeOrExcludeList($include, $exclude, $excludePaths = null)
	{
		$this->initClassMap();
		
		$this->_excludePathList = array();
		if ($excludePaths !== null)
		{
			$tempList = explode(",", str_replace(" ", "", $excludePaths));
			foreach($tempList as $item)
			{
				$this->_excludePathList[] = realpath(dirname(__FILE__)."/../$item");
			}
		}
		
		// load full list of actions and services
		$fullList = array();
		$serviceMap = null;
		$serviceMap = KalturaServicesMap::getMap();
		foreach($serviceMap as $serviceId => $serviceActionItem)
		{
		    /* @var $serviceActionItem KalturaServiceActionItem */
		    foreach ($serviceActionItem->actionMap as $actionId => $actionCallback)
		    {
		        list ($serviceClass, $actionMethodName) = array_values($actionCallback);
		        
		        
    			$servicePath = $this->_classMap[$serviceClass];			
    			if ($this->isPathExcluded($servicePath))
    			{
    				continue;
    			}
    			$fullList[strtolower($serviceId)][strtolower($actionId)] = true;
		    }
		}
					
		$includeList = array();
		if ($include !== null) 
		{
			$tempList = explode(",", str_replace(" ", "", $include));
			foreach($tempList as $item)
			{
				$service = null;
				$action = null;
				$item = strtolower($item);
				if (strpos($item, ".") !== false)
					list($service, $action) = explode(".", $item);
					
				if (!key_exists($service, $includeList))
					$includeList[$service] = array();
					
				if ($action == "*")
				{
					if (!array_key_exists($service, $fullList))
						throw new Exception("Service [$service] not found");
						
					$includeList[$service] = $fullList[$service];
				} 
				else 
					$includeList[$service][$action] = true; 
			}
		}
		else if ($exclude !== null)
		{
			$includeList = $fullList;
			$tempList = explode(",", str_replace(" ", "", $exclude));
			foreach($tempList as $item)
			{
				$service = null;
				$action = null;
				$item = strtolower($item);
				if (strpos($item, ".") !== false)
					list($service, $action) = explode(".", $item);
					
				if ($action == "*")
				{
	//				KalturaLog::debug("Excluding service [$service]");
					unset($includeList[$service]);
				}
				else
				{ 
	//				KalturaLog::debug("Excluding action [$service.$action]");
					unset($includeList[$service][$action]);
				} 
			}
		}
		else
		{
			$includeList = $fullList;
		}
		
		$this->setIncludeList($includeList);
	}

	public function setIncludeList($list)
	{
		$this->_includeList = $list;
	}
	
	public function setIgnoreList($list)
	{
		if(!$list)
			$list = array();
			
		if(is_string($list))
			$list = explode(',', $list);
			
		$this->_typesToIgnore = $list;
	}
	
	public function setAdditionalList($list)
	{
		if ($list === null)
			return;
	
		$includeList = array();
		if(is_array($list))
		{
			$includeList = $list;
		}
		else
		{
			$tempList = explode(",", str_replace(" ", "", $list));
			foreach($tempList as $item)
				if(class_exists($item))
					$includeList[] = $item;
		}
		
		foreach($includeList as $class)
		{
			$classTypeReflector = KalturaTypeReflectorCacher::get($class);
			if($classTypeReflector)
				$this->loadTypesRecursive($classTypeReflector);
		}
	}
	
	/**
	 * sorts the class so the parent classes will appear before the childs
	 */
	public function sortClassTypes(array &$types)
	{
		$typesTree = array();
		
		// first fill the base classes
		foreach($types as $type)
		{
			if (is_null($type->getParentTypeReflector()))
			{
				$typesTree[$type->getType()] = array();
			}
		}
		
		// now fill recursively the childs
		foreach($typesTree as $baseType => $null)
		{
			$this->loadChildsForInheritance($types, $baseType, $typesTree);
		}
		
		// use the tree to sort the types
		$typesNamesInOrder = array();
		$orderedTypes = array();
		$this->flattenArray($typesTree, $typesNamesInOrder);
		foreach($typesNamesInOrder as $typeName)
		{
			foreach($types as $type)
			{
				if ($type->getType() == $typeName)
				{
					$orderedTypes[$typeName] = $type;
					break;
				}
			}
		}

		$types = &$orderedTypes;
	}
	
	private function flattenArray($array, &$out) 
	{
	    foreach($array as $key => $childs)
	    {
	    	$out[$key] = null;
	        if (is_array($childs) && count($childs) > 0)
	            $this->flattenArray($childs, $out);
	    }
	}
	
	private function loadChildsForInheritance(array $types, $parentType, array &$typesTree)
	{
		$typesTree[$parentType] = $this->getChildsForParentType($types, $parentType);
		
		foreach($typesTree[$parentType] as $childClass => $null)
		{
			$this->loadChildsForInheritance($types, $childClass, $typesTree[$parentType]);
		}
	}
	
	private function getChildsForParentType(array $types, $parentType)
	{
		$childs = array();
		foreach($types as $type)
		{
			$currentParentType = ($type->getParentTypeReflector()) ? $type->getParentTypeReflector()->getType() : null;
			$class = $type->getType();
			if ($currentParentType === $parentType) 
			{
				$childs[$class] = array();
			}
		}
		return $childs;
	}
	
	/**
	 * Check if a string ends with another string
	 *
	 * @param string $str
	 * @param string $end
	 * @return boolean
	 */
	protected function endsWith($str, $end) 
	{
		return (substr($str, strlen($str) - strlen($end)) === $end);
	}
	
	/**
	 * Check if a string begins with another string
	 *
	 * @param string $str
	 * @param string $end
	 * @return boolean
	 */
	protected function beginsWith($str, $end) 
	{
		return (substr($str, 0, strlen($end)) === $end);
	}
}