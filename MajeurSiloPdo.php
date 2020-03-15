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
	public $table = 'versions';
	public $moi = '.';
	
	public $colModule = 'module';
	public $colVersion = 'version';
	public $colComm = 'comm';
	
	// Par convention, la 1 est la version à partir de laquelle notre table existe (les 0.x serviront à préparer les extensions etc.).
	public $vTable = '1';
	public $vVerrou = '1';
	public $vComm = '1.1';
	
	public function __construct(PDO $bdd, $paramétrage = array())
	{
		$this->bdd = $bdd;
		if(isset($paramétrage))
			foreach($paramétrage as $option => $val)
				$this->$option = $val; // À FAIRE: un minimum de contrôles sur les options autorisées.
	}
	
	public function déjàJouées()
	{
		$r = array();
		try
		{
		foreach ($this->bdd->query('select '.$this->colModule.', '.$this->colVersion.' from '.$this->table)->fetchAll() as $l)
			$r[$l[$this->colModule]][$l[$this->colVersion]] = true;
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
			switch($this->bdd->getAttribute(PDO::ATTR_DRIVER_NAME))
			{
				case 'sqlite': return;
			}
			$this->bdd->query('lock table '.$this->table);
		}
		catch(PDOException $ex)
		{
			// Le seul cas de pétage possible est si nous-mêmes ne nous sommes pas initialisés.
			if(isset($this->majeur->_àFaire[$this->moi][$this->vVerrou]))
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
	
	public function enregistrer($module, $version, $comm = null)
	{
		$versionBase = $module != $this->moi ? max($this->vTable, $this->vVerrou, $this->vComm) : (isset($this->_enregistrementEnCoursVersion) ? $this->_enregistrementEnCoursVersion : $version);
		if(isset($this->_exceptionTolérée))
		{
			// Si on a laissé passer une exception sur le verrouillage, croyant que nous n'avions pas encore de quoi poser le verrou et qu'il nous fallait donc être tolérants jusque-là; mais que finalement on se rend compte qu'on essaie de nous emberlificoter (de passer une MàJ hors-sujet par rapport à notre volonté de converger au plus vite vers une base verrouillable), mieux vaut péter tard que jamais.
			if(version_compare($versionBase, $this->vVerrou) > 0)
				throw $ex;
			unset($this->_exceptionTolérée);
		}
		if(version_compare($versionBase, $this->vComm) >= 0)
		{
			$this->_enregistrerLesGardésPourPlusTard($version);
			if(isset($this->_commentairesPourPlusTard))
			{
				foreach($this->_commentairesPourPlusTard as $moduleAncienComm => $versionsAncienComm)
					foreach($versionsAncienComm as $versionAncienComm => $ancienComm)
						$this->bdd->prepare('update '.$this->table.' set '.$this->colComm.' = :comm where '.$this->colModule.' = :module and '.$this->colVersion.' = :version and '.$this->colComm.' is null')->execute(array('module' => $moduleAncienComm, 'version' => $versionAncienComm, 'comm' => $ancienComm));
				unset($this->_commentairesPourPlusTard);
			}
			$req = $this->bdd->prepare('insert into '.$this->table.' ('.$this->colModule.', '.$this->colVersion.', '.$this->colComm.') values (:module, :version, :comm)');
			$req->execute(array('module' => $module, 'version' => $version, 'comm' => $comm));
		}
		else if(version_compare($versionBase, $this->vTable) >= 0)
		{
			$this->_enregistrerLesGardésPourPlusTard($version);
			$req = $this->bdd->prepare('insert into '.$this->table.' ('.$this->colModule.', '.$this->colVersion.') values (:module, :version)');
			$req->execute(array('module' => $module, 'version' => $version));
			if(isset($comm))
				$this->_commentairesPourPlusTard[$module][$version] = $comm;
		}
		else
		{
			// Si la table n'est pas encore en place, on n'a rien de mieux (mais c'est fragile) que de garder en mémoire cela, dans le but de l'écrire dès que la table sera présente (et espérer qu'on ne plantera pas entretemps).
			$this->_gardésPourPlusTard[] = func_get_args();
		}
	}
	
	protected function _enregistrerLesGardésPourPlusTard($versionEnCours)
	{
		if(isset($this->_gardésPourPlusTard))
		{
			$àEnregistrer = $this->_gardésPourPlusTard;
			unset($this->_gardésPourPlusTard);
			$this->_enregistrementEnCoursVersion = $versionEnCours;
			try
			{
				foreach($àEnregistrer as $ligne)
					call_user_func_array(array($this, 'enregistrer'), $ligne);
			}
			catch(Exception $ex)
			{
				unset($this->_enregistrementEnCoursVersion);
				throw $ex;
			}
			unset($this->_enregistrementEnCoursVersion);
		}
	}
	
	public function valider()
	{
		$this->bdd->commit();
	}
	
	/*- Initialisation -------------------------------------------------------*/
	/* Où l'on parle d'œuf et de poule, et où nous sommes aussi considérés comme un Listeur de ce dont nous-mêmes avons besoin. */
	
	public function initialiser()
	{
		if(!isset($this->installs))
			$this->installs = array
			(
				$this->vVerrou => 'create table '.$this->table.' (quand '.$this->_timestampdefaultnow().', '.$this->colModule.' varchar(255), '.$this->colVersion.' varchar(31))',
				$this->vComm => 'alter table '.$this->table.' add column '.$this->colComm.' text',
			);
	}
	
	protected function _timestampdefaultnow()
	{
		switch($this->bdd->getAttribute(PDO::ATTR_DRIVER_NAME))
		{
			case 'sqlite': return "timestamp default (datetime('now', 'localtime'))"; // https://stackoverflow.com/a/46419101/1346819
		}
		return 'timestamp default now()';
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
		return $sql;
	}
}

?>
