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

require_once dirname(__FILE__).'/../util/GlobExpr.php';

/**
 * Cherche des fichiers de mise-à-jour dans un certain nombre de dossiers.
 */
class MajeurListeurDossiers implements MajeurListeur
{
	public $sépÉlémentsModule = '/';
	
	/**
	 * Initialise.
	 *
	 * @param array $globDossiers glob() des dossiers à fouiller.
	 * @param string $exprChemins Regex permettant de trouver les fichiers à jouer, comprenant des blocs nommés app[0…9] (pour les éléments de l'application) et version (pour le numéro de version).
	 */
	public function __construct($globDossiers, $exprChemins = null, $suffixes = null)
	{
		if($suffixes)
			$this->_initParFixes($globDossiers, $exprChemins, $suffixes);
		else
			$this->_init($globDossiers, $exprChemins);
	}
	
	public static function ExprFichiers($préfixe, $suffixes)
	{
		return $préfixe.'(?P<version>[0-9][.0-9]*)(?:-[^/]*)?\.(?:'.(is_array($suffixes) ? implode('|', $suffixes) : $suffixes).')';
	}
	
	protected function _initParFixes($globDossiers, $préfixe, $suffixes)
	{
		$this->_init($globDossiers, self::ExprFichiers($préfixe, $suffixes));
	}
	
	protected function _init($globDossiers, $exprChemins = null, $suffixes = null)
	{
		// Deux modes d'appel:
		// - Par glob + expr: __construct('../(?P<app>*)/installs', 'maj-(?P<version>[0-9][.0-9]*)(?:-[^/]*)\.(?:sql|php)')
		// - Par expr uniquement: __construct('\.\./(?P<app>[^/]*)/installs/maj-(?P<version>[0-9][.0-9]*)(?:-[^/]*)?\.(?:sql|php)')
		if(!$exprChemins)
			$exprChemins = $globDossiers;
		else
			// Conversion du glob en expr.
			$exprChemins = GlobExpr::globEnExpr($globDossiers).'/'.$exprChemins;
		
		$this->_numProchaineCapture = 0;
		$exprChemins = preg_replace_callback('#{[^}]*}#', array($this, '_accoladeEnCapture'), $exprChemins);
		
		// Création de la regex finale.
		
		foreach(array('#', '@', '%', '&', null) as $borne)
			if(!isset($borne))
				throw new Exception('Impossible de délimiter la regex: '.$exprChemins);
			else if(strpos($exprChemins, $borne) === false)
				break;
		$this->_expr = $borne.'^'.$exprChemins.'$'.$borne;
		
		// Et on recalcule le ou les glob résultants.
		
		$this->_globs = GlobExpr::exprEnGlobs($exprChemins);
	}
	
	public function _accoladeEnCapture($attrapage)
	{
		return '(?P<app'.(++$this->_numProchaineCapture).'>'.substr($attrapage[0], 1, -1).')';
	}
	
	/**
	 * Renvoie la liste des mises-à-jour potentielles du système.
	 *
	 * @return array [ [ <module>, <version>, <info> ], [ etc. ] ]; <info> est par exemple l'URL de la chose à jouer.
	 */
	public function lister()
	{
		$fichiers = array();
		foreach($this->_globs as $glob)
			$fichiers += array_flip(glob($glob));
		$majs = array();
		foreach($fichiers as $chemin => $trou)
			if(preg_match($this->_expr, $chemin, $r))
			{
				if(!isset($r['version']))
					throw new Exception('Chemin sans indication de version: '.$chemin); // À FAIRE: indiquer la regex, et le résultat.
				$élémentsApp = array();
				foreach($r as $clé => $val)
					if(substr($clé, 0, 3) == 'app' && strlen($val = trim($val)))
						$élémentsApp[0 + substr($clé, 3)] = $val;
				ksort($élémentsApp, SORT_NUMERIC);
				$app = implode($this->sépÉlémentsModule, $élémentsApp);
				if(isset($this->trad))
					foreach($this->trad as $regex => $trad)
						$app = preg_replace($regex, $trad, $app);
				$majs[] = array($app, $r['version'], $chemin);
			}
			// À FAIRE: else notifier qu'un fichier correspondait au glob() mais que le crible (plus sévère) de l'expr l'a éliminé: erreur de nommage?
		
		return $majs;
	}
}

?>
