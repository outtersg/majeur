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

require_once dirname(__FILE__).'/MajeurDiag.php';
require_once dirname(__FILE__).'/MajeurSilo.php';
require_once dirname(__FILE__).'/MajeurListeur.php';
require_once dirname(__FILE__).'/MajeurJoueur.php';

/**
 * Effectue des mises-à-jour (MàJ).
 */
class Majeur
{
	public function __construct(MajeurSilo $silo, $listeurs, $joueurs)
	{
		$this->diag = new MajeurDiag;
		$this->silo = $silo;
		$this->silo->majeur = $this;
		
		// Les listeurs.
		
		$this->listeurs = array();
		// Si le silo a besoin de s'initialiser, on le glisse en premier: ainsi ses MàJ seront-elles premières en lice pour exécution.
		if($this->silo instanceof MajeurListeur)
			$this->listeurs[] = $this->silo;
		$this->listeurs = array_merge($this->listeurs, is_array($listeurs) ? $listeurs : array($listeurs));
		
		// Les exécutants.
		
		$this->joueurs = is_array($joueurs) ? $joueurs : array($joueurs);
		foreach($this->joueurs as $joueur)
			$joueur->majeur = $this;
	}
	
	protected function _calculerResteÀJouer()
	{
		$this->_faites = $this->silo->déjàJouées();
		foreach($this->_àFaire as $module => & $ptrMàjsModule)
		{
			if(isset($this->_faites[$module]))
				$ptrMàjsModule = array_diff_key($ptrMàjsModule, $this->_faites[$module]);
			uksort($ptrMàjsModule, 'version_compare');
			if(!count($ptrMàjsModule))
				unset($this->_àFaire[$module]);
		}
	}
	
	public function tourner()
	{
		$this->_courante = null;
		$this->silo->initialiser();
		$this->_àFaire = $this->_listerParModule();
		$this->_bloquées = array();
		while(true)
		{
			$this->silo->verrouiller();
			// On recalcule à chaque tour de boucle la liste de trucs restant à faire: en effet la précédente mise-à-jour peut avoir fait une "avance rapide", faisant passer artificiellement plusieurs autres mises-à-jour; nous devons donc rafraîchir notre _àFaire pour en tenir compte.
			// Ce recalcul est fait dans le verrouillage, pour nous assurer que nous sommes seuls à interagir avec la base à ce moment.
			$this->_calculerResteÀJouer();
			// Si finalement il n'y a plus rien à faire.
			if(!count($this->_àFaire))
				break;
			
			// Quelle est la prochaine non bloquée?
			if(!($prochaine = $this->_prochaine()))
				$this->_interdépendance(); // Si l'on a fait le tour avec uniquement des bloquées, problème.
			list($module, $version, $info) = $prochaine;
					
			$this->jouerEtDéverrouiller($module, $version); // À FAIRE: si true, faire les trucs. Mais attention, un #skip peut avoir aussi retirés->_àFaire.
		}
	}
	
	protected function _listerParModule()
	{
		$r = array();
		foreach($this->listeurs as $listeur)
		{
			$màjsListeur = $listeur->lister();
			if(!count($màjsListeur))
				throw new Exception('Aucune mise-à-jour remontée par '.get_class($listeur));
			foreach($màjsListeur as $màj)
			{
			if(isset($r[$màj[0]][$màj[1]]))
				throw new Exception('Deux mises-à-jour '.$màj[0].' '.$màj[1].': '.$this->_libelléMàj($r[$màj[0]][$màj[1]]).', '.$this->_libelléMàj($màj[2]));
			$r[$màj[0]][$màj[1]] = $màj[2];
			if(isset($màj[3]))
				$this->méta[$màj[0]][$màj[1]] = array($màj[3]);
			}
		}
		return $r;
	}
	
	protected function _débloquer($m, $v)
	{
		unset($this->_bloquées[$m][$v]);
		if(!count($this->_bloquées[$m]))
			unset($this->_bloquées[$m]);
		$débloqué = array($m, $v);
		foreach($this->_bloquées as $attendantM => $attendus)
			foreach($attendus as $attendantV => $attendu)
				if($attendu == $débloqué)
					$this->_débloquer($attendantM, $attendantV);
	}
	
	protected function _prochaine()
	{
		foreach($this->_àFaire as $module => $versions)
		{
			foreach($versions as $version => $info) break;
			if(!isset($this->_bloquées[$module][$version])) // Si nous n'avons pas encore été bloqués.
				return array($module, $version, $info);
			else if(isset($this->_faites[$module][$version])) // Ou si depuis le dernier passage la situation s'est débloquée.
			{
				$this->_débloquer($module, $version);
				return array($module, $version, $info);
			}
		}
	}
	
	protected function _interdépendance()
	{
		$boucle = array();
		foreach($this->_bloquées as $module => $versions)
		{
			foreach($versions as $version => $bloqueur)
			{
				for($m = $module, $v = $version; !isset($boucle[$m]);)
				{
					$boucle[$m] = $m.' '.$v;
					list($m, $v) = $this->_bloquées[$m][$v];
				}
				$boucle[] = $m.' '.$v; // Pas [$m], car c'est le même module que notre module initial, on écraserait notre point de départ.
				throw new Exception('Mises-à-jour interdépendantes sur le module '.$module.': '.implode(' <- ', $boucle));
			}
		}
	}
	
	public function jouerEtDéverrouiller($module, $version)
	{
		$info = $this->_àFaire[$module][$version];
		unset($this->_àFaire[$module][$version]);
		$this->_bloquées[$module][$version] = true; // Histoire qu'on se sache en cours.
		$this->_courante = array($module, $version);
		try
		{
			$this->diag->normal("=== $module $version ===\n(".$this->_libelléMàj($info).")\n");
			$joueur = null;
			if(isset($this->méta[$module][$version][0]))
				$joueur = $this->méta[$module][$version][0];
			else
				foreach($this->joueurs as $candidat)
					if($candidat->saitJouer($module, $version, $info))
				{
						$joueur = $candidat;
					break;
				}
			if(!$joueur)
				throw new Exception("Aucun Joueur pour exécuter $module $version (".(is_string($info) ? $info : serialize($info)).")");
			$joueur->jouer($module, $version, $info);
			$this->silo->valider($module, $version);
			$this->_débloquer($module, $version);
			$this->_faites[$module][$version] = $info;
		}
		catch(Exception $ex)
		{
			$this->silo->annuler();
			$this->_àFaire[$module][$version] = $info;
			if($ex instanceof MajeurEnAttente)
			{
				$this->diag->normal("(temporisée, en attente de ".$ex->bloqueur[0]." ".$ex->bloqueur[1].")\n");
				$this->_bloquées[$module][$version] = $ex->bloqueur;
			}
			else
			{
				$this->_débloquer($module, $version);
				throw $ex;
			}
		}
		$this->_courante = null;
	}
	
	public function requérir($module, $version)
	{
		if(isset($this->_faites[$module][$version]))
			return;
		else if(!isset($this->_àFaire[$module][$version]) && !isset($this->_bloquées[$module][$version]))
			throw new Exception('Mise-à-jour '.$module.' '.$version.' introuvable');
		else
			throw new MajeurEnAttente('En attente de '.$module.' '.$version, array($module, $version));
	}
	
	protected function _descrMàj($module, $version, $info)
	{
		return "$module $version (".$this->_libelléMàj($info).")";
	}
	
	protected function _libelléMàj($info)
	{
		if(is_string($info))
			return $info;
		if(is_object($info))
			if(method_exists($info, '__toString'))
				return $info->__toString();
			else
				return get_class($info).' '.spl_object_hash($info);
		return serialize($info);
	}
}

class MajeurEnAttente extends Exception
{
	public function __construct($message, $moduleVersion)
	{
		parent::__construct($message);
		$this->bloqueur = $moduleVersion;
	}
}

?>
