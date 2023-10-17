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

require_once dirname(__FILE__).'/MajeurJoueur.php';
require_once dirname(__FILE__).'/../sqleur/Sqleur.php';
require_once dirname(__FILE__).'/../sqleur/SqleurPreproIncl.php';
if(file_exists($f = dirname(__FILE__).'/../sqleur/SqleurPreproDef.php')) require_once $f;

/**
 * Exécute une mise-à-jour.
 */
class MajeurJoueurPdo implements MajeurJoueur
{
	public $récapNMax = 1024;
	public $récapTMax = 1048576;
	
	public function __construct($bdd, $défs = array())
	{
		$this->bdd = $bdd;
		$préprocs = array
		(
			$this,
			new SqleurPreproIncl(),
		);
		if(class_exists('SqleurPreproDef'))
			$préprocs[] = new SqleurPreproDef();
		$this->sqleur = new Sqleur(array($this, '_jouerRequête'), $préprocs);
		switch($this->pilote())
		{
			case 'sqlite': $this->sqleur->_mode |= Sqleur::MODE_BEGIN_END; break;
		}
		$this->défs = $défs;
		
		$pasCetteFois = array
		(
			'(passe|ignore) (juste |seulement |)(aujourd\'hui|temp(orairement)?|(cette|une) fois(-ci)?)',
			'pas cette fois|une autre fois',
			'skip (only )?(once|today)',
			'not (now|today)',
		);
		$jamaisDeLaVie = array
		(
			'(passe|ignore)',
			'skip',
		);
		$exprPasser = array();
		foreach(array('pasCetteFois', 'jamaisDeLaVie') as $composant)
		{
			$sousExpr = $$composant;
			$exprPasser[] = '(?P<'.$composant.'>#(?:'.strtr(implode('|', $sousExpr), array('(' => '(?:', ' ' => '\s+')).'))';
		}
		$this->exprPasser = '%^(?:'.implode('|', $exprPasser).')\s*%';
		
		if(method_exists($bdd, 'pgsqlSetNoticeCallback'))
			$bdd->pgsqlSetNoticeCallback(array($this, 'notifDiag'));
	}
	
	public function saitJouer($module, $version, $info)
	{
		return is_string($info) && file_exists($info) && substr($info, -4) == '.sql';
	}
	
	/**
	 * Exécute une mise-à-jour.
	 *
	 * @param string $module Module de la MàJ.
	 * @param string $version Version en semver.
	 * @param mixed $info Truc à jouer (retour du listeur; par exemple une URI).
	 */
	public function jouer($module, $version, $info)
	{
		$this->moduleCourant = $module;
		$this->init();
		if(file_exists($info))
		$this->sqleur->decoupeFichier($info);
		else
			$this->sqleur->decoupe($info);
		
		return $this->récap();
	}
	
	protected function init()
	{
		$définitionsParPilote = array
		(
			'pgsql' => array
			(
				'AUTOPRIMARY' => 'serial primary key',
			),
			'sqlite' => array
			(
				'AUTOPRIMARY' => 'integer primary key',
			),
		);
		$pilote = $this->pilote();
		$this->sqleur->avecDefinitions($définitionsParPilote[$pilote] + array
		(
			':pilote' => $pilote,
			':driver' => $pilote,
		) + $this->défs);
		
		$this->_récap = null;
		$this->_récapN = 0;
		$this->_récapNPlus = 0;
		$this->_récapTPlus = 0;
	}
	
	public function affDurée($secondes)
	{
		return $secondes >= 1 ? sprintf('%.3f s', $secondes) : sprintf('%d ms', ceil($secondes * 1000));
	}
	
	protected function récap()
	{
		if(isset($this->_récap))
		{
			$récap = $this->_récap;
			if($this->_récapNPlus && $this->_récapTPlus)
				$récap .= "\n-- + ".$this->_récapNPlus." autres requêtes [".$this->affDurée($this->_récapTPlus)."]";
			return $récap;
		}
	}
	
	protected function _récap($req, $durée)
	{
		$tout = ($this->_récap ? $this->_récap."\n" : '').$req.'; -- '.$this->affDurée($durée);
		if(strlen($tout) < $this->récapTMax - 30 && $this->_récapN < $this->récapNMax)
		{
			++$this->_récapN;
			$this->_récap = $tout;
		}
		else
		{
			++$this->_récapNPlus;
			$this->_récapTPlus += $durée;
		}
	}
	
	public function notifDiag($message)
	{
		$this->majeur->diag->normal("\n> ".trim($message));
	}
	
	public function _jouerRequête($sql)
	{
		$t0 = microtime(true);
		$this->majeur->diag->info($sql.' ');
		try
		{
			$ex = null;
			$rés = $this->bdd->query($sql);
			$t1 = microtime(true);
		}
		catch(Exception $ex)
		{
			require_once dirname(__FILE__).'/../sqleur/SqlUtils.php';
			$u = new SqlUtils();
			throw $u->jolieEx($ex, $sql);
		}
		
		$durée = microtime(true) - $t0;
		if($ex || $durée >= 10)
			$sortie = 'erreur';
		else if($durée >= 1)
			$sortie = 'alerte';
		else
			$sortie = 'bon';
		
		$affDurée = $this->affDurée($durée);
		$this->majeur->diag->$sortie($ex ? "\n".'[4m/!\\[24m '.$ex->getMessage()."\n" : '[ '.$affDurée.' ]'."\n");
		
		$this->_récap($sql, $durée);
		
		if($ex)
			throw $ex;
		
		return $rés;
	}
	
	public function préprocesse($motClé, $directive)
	{
		switch($motClé)
		{
			case '#req':
			case '#requiers':
			case '#requiert':
			case '#require':
				$req = preg_split('/\s+/', $directive);
				$this->majeur->requérir($req[1], $req[2]);
				return;
			default:
				if(preg_match($this->exprPasser, $directive, $r))
				{
					$req = preg_replace('/\s*#.*/', '', substr($directive, strlen($r[0])));
					$req = $req ? preg_split('/\s+/', $req) : array();
					$àPasser = array();
					$moduleÀPasser = null;
					$moduleÀPasserExploité = false;
					foreach($req as $truc)
						if(preg_match('/^[0-9*]+(?:\.[0-9*]+)*$/', $truc))
						{
							if(!isset($moduleÀPasser))
								$moduleÀPasser = $this->moduleCourant;
							$àPasser[$moduleÀPasser][$truc] = true;
							$moduleÀPasserExploité = true;
						}
						else
						{
							$moduleÀPasser = $truc;
							$moduleÀPasserExploité = false;
						}
					if(isset($moduleÀPasser) && !$moduleÀPasserExploité)
					{
						$classeEx = 'ErreurExpr';
						class_exists($classeEx) || $classeEx = 'Exception';
						throw new $classeEx($motClé.' '.$moduleÀPasser.': quelle version du module faut-il passer?');
					}
					$définitivement = empty($r['pasCetteFois']);
					foreach($àPasser as $module => $versions)
						foreach($versions as $version => $trou)
							$this->majeur->passer($module, $version, $définitivement);
					if(!count($àPasser))
						$this->majeur->passer(null, null, $définitivement);
					return;
				}
				break;
		}
		
		return false;
	}
	
	public function pilote()
	{
		return $this->bdd()->getAttribute(PDO::ATTR_DRIVER_NAME);
	}
	
	/**
	 * Renvoie la base bas-niveau (objet PDO) à laquelle nous sommes connectés.
	 */
	public function bdd()
	{
		return $this->bdd;
	}
	
	public $bdd;
	public $sqleur;
	public $_sqleur;
	public $majeur;
	public $défs;
	protected $exprPasser;
	protected $_récap;
	protected $_récapN;
	protected $_récapNPlus;
	protected $_récapTPlus;
}

?>
