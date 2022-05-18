#!/usr/bin/env php
<?php

// Connect to the database.
$dsn = 'mysql:unix_socket=/tmp/mysql.sock;dbname=consent_decrees;charset=utf8';
$connection = new \PDO($dsn, 'jeff', '');

// Get the base properties for consent decrees.
$documents_results = $connection->query('select * from consentDecree', PDO::FETCH_OBJ);

$documents = [];
// print_r($test);

// Loop through the consent decrees.
foreach ($documents_results as $num => $document) {
  $cdid = $document->cdid;

  // Load and attach fields.
  $document->fields = new stdClass;

  $field_results = $connection->query(<<<SQL
  select cdd.cdid, ci.catItemID, ci.parentid, ci.displayName, cdd.fieldValue, ci.itemType, ci2.displayName as parentDisplayName,
    CASE ci.itemType
      WHEN 'select' THEN (select displayName from catitems where catItemID = cdd.fieldValue)
      ELSE ''
    END as optionValue
  from cdDetail cdd
    inner join catitems ci on cdd.catitemid = ci.catItemID
    inner join catitems ci2 on ci.parentid = ci2.catItemID
  -- where ci.itemType not in ('checkclause');
  where cdd.cdid = {$cdid} and ci.itemType not in ('checkclause', 'checkClause');
  SQL, PDO::FETCH_OBJ);

  foreach ($field_results as $field) {
    switch ($field->itemType) {
      case 'text':
        $document->fields->{$field->displayName} = $field->fieldValue;
        break;
      case 'select':
        $document->fields->{$field->displayName} = $field->optionValue;
        break;
      case 'checkbox':
      case 'checkBox':
        $document->fields->{$field->parentDisplayName}[] = $field->displayName;
        break;
    }
  }

  // Load and attach clauses.
  $document->clauses = [];

  $clause_results = $connection->query(<<<SQL
  select ci.displayName, cdc.clauseText
  from cdClause cdc
    inner join catitems ci on cdc.clauseid = ci.catItemID
  where cdc.cdid = {$cdid};
  SQL, PDO::FETCH_OBJ);

  foreach ($clause_results as $clause) {
    $document->clauses[$clause->displayName] = $clause->clauseText;
  }

  // Load and attach attorneys.
  $document->plaintiffCounsel = [];
  $document->defendantCounsel = [];

  $attorney_results = $connection->query(<<<SQL
  select cfa.firmType, f.firmName, a.attorneyfullName
  from cdFirmAttorney cfa
    inner join firm f on cfa.firmid = f.firmid
    inner join attorney a on cfa.attorneyid = a.attorneyid
  where cfa.cdid = {$cdid} and cfa.firmType in ('Defendant', 'Plaintiff');
  SQL, PDO::FETCH_OBJ);

  foreach ($attorney_results as $attorney) {
    if ($attorney->firmType === 'Defendant') {
      $document->defendantCounsel[$attorney->firmName][] = $attorney->attorneyfullName;
    }
    else {
      $document->plaintiffCounsel[$attorney->firmName][] = $attorney->attorneyfullName;
    }
  }

  $documents[] = $document;
}

// Convert into json.
$json = json_encode($documents, JSON_PRETTY_PRINT);

// Display and WIN!
echo $json;
