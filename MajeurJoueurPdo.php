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

/**
 * Exécute une mise-à-jour.
 */
class MajeurJoueurPdo implements MajeurJoueur
{
	public function __construct($bdd)
	{
		$this->bdd = $bdd;
		$this->sqleur = new Sqleur(array($this, '_jouerRequête'));
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
		$this->init();
		$this->sqleur->decoupeFichier($info);
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
		$pilote = $this->bdd->getAttribute(PDO::ATTR_DRIVER_NAME);
		$this->sqleur->avecDefinitions($définitionsParPilote[$pilote] + array
		(
			':pilote' => $pilote,
			':driver' => $pilote,
		));
	}
	
	public function _jouerRequête($sql)
	{
		$t0 = microtime(true);
		$this->majeur->diag->info($sql.' ');
		try
		{
			$ex = null;
			$this->bdd->query($sql);
			$t1 = microtime(true);
		}
		catch(Exception $ex)
		{
		}
		
		$durée = microtime(true) - $t0;
		if($ex || $durée >= 10)
			$sortie = 'erreur';
		else if($durée >= 1)
			$sortie = 'alerte';
		else
			$sortie = 'bon';
		
		$durée = $durée >= 1 ? sprintf('%.3f s', $durée) : sprintf('%d ms', ceil($durée * 1000));
		$this->majeur->diag->$sortie($ex ? "\n".'^[[4m/!\\^[[24m '.$ex->getMessage()."\n" : '[ '.$durée.' ]'."\n");
		
		if($ex)
			throw $ex;
	}
}

?>
