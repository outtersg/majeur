<?php
/*
 * Copyright (c) 2013,2016,2019 Guillaume Outters
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.  IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

/**
 * Enregistre les mises-à-jour effectuées.
 */
class MajeurSiloPdo implements MajeurSilo
{
	public $moi = '.';
	
	public function __construct(PDO $bdd, $table)
	{
		$this->bdd = $bdd;
		$this->table = $table;
	}
	
	public function déjàJouées()
	{
		$r = array();
		foreach ($this->bdd->query('select module, version from '.$this->table)->fetchAll() as $l)
			$r[$l['module']][$l['version']] = true;
		
		return $r;
	}
	
	public function verrouiller($avecVraiVerrouillage = true)
	{
		$this->bdd->beginTransaction();
		if($avecVraiVerrouillage)
			$this->bdd->query('lock table '.$this->table);
	}
	
	public function annuler()
	{
		$this->bdd->rollback();
	}
	
	public function valider($module, $version, $comm = null)
	{
		if($module != $this->moi || $version >= 1)
		{
			$req = $this->bdd->prepare('insert into '.$this->table.' (module, version, comm) values (:module, :version, :comm)');
			$req->execute(array('module' => $module, 'version' => $version, 'comm' => $comm));
		}
		else
		{
			$req = $this->bdd->prepare('insert into '.$this->table.' (module, version) values (:module, :version)');
			$req->execute(array('module' => $module, 'version' => $version));
		}
		$this->bdd->commit();
	}
	
	/*- Initialisation -------------------------------------------------------*/
	/* Où l'on parle d'œuf et de poule */
	
	public function initialiser()
	{
		// Première tentative, optimiste.
		
		try
		{
			$r = $this->déjàJouées();
		}
		catch(PDOException $ex)
		{
			// Si l'on atterit sur une base vierge, peut-être notre table n'existe-t-elle même pas, d'où l'exception. Considérons que nous n'avons même pas joué notre propre initialisation, avant de retenter.
			$r = array();
		}
		
		// Seconde tentative
		
		if($this->_installer($r))
			$this->déjàJouées(); // Par acquis de conscience.
	}
	
	/**
	 * Installe notre propre schéma.
	 *
	 * @return boolean true si des mises-à-jour ont dû être jouées.
	 */
	protected function _installer($déjàJouées)
	{
		$installs = array
		(
			0 => 'create table '.$this->table.' (quand timestamp default now(), module varchar(255), version varchar(31))',
			1 => 'alter table '.$this->table.' add column comm text',
		);
		
		if(isset($déjàJouées[$this->moi]))
			$installs = array_diff_key($installs, $déjàJouées[$this->moi]);
		if(!count($installs))
			return false;
		
		foreach($installs as $version => $install)
			$this->_jouer($this->moi, $version, $install);
		
		return true;
	}
	
	protected function _jouer($module, $version, $sql)
	{
		// On ne peut verrouiller la table que si elle existe, donc en version 1 ou supérieure.
		$this->verrouiller($module != $this->moi || $version > 0);
		$this->bdd->query($sql);
		$this->valider($module, $version, $sql);
	}
}

?>
