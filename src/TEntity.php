<?php

/**
 * This file is part of the Inlm\QueryObject extension
 * for the Lean Mapper library (http://leanmapper.com)
 * Copyright (c) 2013 Michal Bohuslávek
 */

namespace Inlm\QueryObject;

use LeanMapper;
use LeanMapper\Filtering;
use LeanMapper\Fluent;
use LeanMapper\Reflection\Property;
use LeanMapper\Relationship;
use Inlm\QueryObject\Caller;
use Inlm\QueryObject\Exception\InvalidArgumentException;
use Inlm\QueryObject\Exception\InvalidMethodCallException;
use Inlm\QueryObject\Exception\InvalidRelationshipException;
use Inlm\QueryObject\Exception\InvalidStateException;
use Inlm\QueryObject\Exception\MemberAccessException;
use Inlm\QueryObject\IQuery;

trait TEntity
{
	protected function queryProperty($field, IQuery $query)
	{
		if ($this->isDetached()) {
			throw new InvalidStateException('Cannot query detached entity.');
		}
		$property = $this->getCurrentReflection()->getEntityProperty($field);
		if ($property === NULL) {
			throw new MemberAccessException("Cannot access undefined property '$field' in entity " . get_called_class() . '.');
		}
		if (!$property->hasRelationship()) {
			throw new InvalidArgumentException("Property '{$property->getName()}' in entity ". get_called_class() . " has no relationship.");
		}
		$class = $property->getType();
		$filters = $this->createImplicitFilters($class, new Caller($this, $property))->getFilters();
		$mapper = $this->mapper;
		$filters[] = function (Fluent $fluent) use ($mapper, $query) {
			$query->applyQuery($fluent, $mapper);
		};

		$relationship = $property->getRelationship();
		if ($relationship instanceof Relationship\BelongsToMany) {
			$targetTable = $relationship->getTargetTable();
			$referencingColumn = $relationship->getColumnReferencingSourceTable();
			$rows = $this->row->referencing($targetTable, $referencingColumn, new Filtering($filters));

		} elseif ($relationship instanceof Relationship\HasMany) {
			$relationshipTable = $relationship->getRelationshipTable();
			$sourceReferencingColumn = $relationship->getColumnReferencingSourceTable();
			$targetReferencingColumn = $relationship->getColumnReferencingTargetTable();
			$targetTable = $relationship->getTargetTable();
			$targetPrimaryKey = $mapper->getPrimaryKey($targetTable);
			$rows = array();
			$resultRows = array();
			$targetResultProxy = NULL;

			foreach ($this->row->referencing($relationshipTable, $sourceReferencingColumn) as $relationship) {
				$row = $relationship->referenced($targetTable, $targetReferencingColumn, new Filtering($filters));
				if ($row !== NULL && $targetResultProxy === NULL) {
					$targetResultProxy = $row->getResultProxy();
				}
				$row !== NULL && $resultRows[$row->{$targetPrimaryKey}] = $row;
			}

			if ($targetResultProxy) {
				foreach ($targetResultProxy as $rowId => $rowData) {
					if (isset($resultRows[$rowId])) {
						$rows[] = $resultRows[$rowId];
					}
				}
			} else {
				$rows = $resultRows;
			}

		} else {
			throw new InvalidRelationshipException('Only BelongsToMany and HasMany relationships are supported when querying entity property. ' . get_class($relationship) . ' given.');
		}
		$entities = array();
		$table = $mapper->getTable($class);
		foreach ($rows as $row) {
			$entity = $this->entityFactory->createEntity($mapper->getEntityClass($table, $row), $row);
			$entity->makeAlive($this->entityFactory);
			$entities[] = $entity;
		}
		return $entities;
	}
}
