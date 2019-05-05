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
class MajeurSiloPdo implements MajeurSilo, MajeurListeur
{
	public $moi = '.';
	
	// Par convention, la 1 est la version à partir de laquelle notre table existe (les 0.x serviront à préparer les extensions etc.).
	const V_VERROU = '1';
	const V_COMM = '1.1';
	
	public function __construct(PDO $bdd, $table)
	{
		$this->bdd = $bdd;
		$this->table = $table;
	}
	
	public function déjàJouées()
	{
		$r = array();
		try
		{
		foreach ($this->bdd->query('select module, version from '.$this->table)->fetchAll() as $l)
			$r[$l['module']][$l['version']] = true;
		}
		catch(PDOException $ex)
		{
			if(!isset($this->_exceptionTolérée))
				throw $ex;
			$this->bdd->rollback();
			$this->bdd->beginTransaction();
		}
		
		return $r;
	}
	
	public function verrouiller()
	{
		$this->bdd->beginTransaction();
		
		try
		{
			$this->bdd->query('lock table '.$this->table);
		}
		catch(PDOException $ex)
		{
			// Le seul cas de pétage possible est si nous-mêmes ne nous sommes pas initialisés.
			if(isset($this->majeur->_àFaire[$this->moi][MajeurSiloPdo::V_VERROU]))
			{
				$this->bdd->rollback();
				$this->bdd->beginTransaction();
				// Et encore, on ne sait pas pour quelle version on nous demande de verrouiller: on vous a à l'œil, on vérifiera tout ça à la sortie.
				$this->_exceptionTolérée = $ex;
			}
			else
				throw $ex;
		}
	}
	
	public function annuler()
	{
		$this->bdd->rollback();
	}
	
	public function valider($module, $version, $comm = null)
	{
		if(isset($this->_exceptionTolérée))
		{
			// Si on a laissé passer une exception sur le verrouillage, croyant que nous n'avions pas encore de quoi poser le verrou et qu'il nous fallait donc être tolérants jusque-là; mais que finalement on se rend compte qu'on essaie de nous emberlificoter (de passer une MàJ hors-sujet par rapport à notre volonté de converger au plus vite vers une base verrouillable), mieux vaut péter tard que jamais.
			if($module != $this->moi || version_compare($version, MajeurSiloPdo::V_VERROU) > 0)
				throw $ex;
			unset($this->_exceptionTolérée);
		}
		if($module != $this->moi || version_compare($version, MajeurSiloPdo::V_COMM) >= 0)
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
	/* Où l'on parle d'œuf et de poule, et où nous sommes aussi considérés comme un Listeur de ce dont nous-mêmes avons besoin. */
	
	public function initialiser()
	{
		if(!isset($this->installs))
			$this->installs = array
			(
				MajeurSiloPdo::V_VERROU => 'create table '.$this->table.' (quand timestamp default now(), module varchar(255), version varchar(31))',
				MajeurSiloPdo::V_COMM => 'alter table '.$this->table.' add column comm text',
			);
	}
	
	public function lister()
	{
		$r = array();
		foreach($this->installs as $version => $sql)
			$r[] = array($this->moi, $version, $sql, $this);
		return $r;
	}
	
	public function jouer($module, $version, $sql)
	{
		$this->bdd->query($sql);
	}
}

?>
