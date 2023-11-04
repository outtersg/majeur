<?php
/*
 * Copyright (c) 2016,2019 Guillaume Outters
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

/**
 * Exécute une mise-à-jour.
 */
class MajeurJoueurPhp implements MajeurJoueur
{
	public $récapTMax = 0x10000;
	
	public function saitJouer($module, $version, $info)
	{
		return is_string($info) && file_exists($info) && substr($info, -4) == '.php';
	}
	
	/**
	 * Exécute une mise-à-jour.
	 *
	 * @param string $module Module de la MàJ.
	 * @param string $version Version en semver.
	 * @param mixed $info Truc à jouer (retour du listeur; par exemple une URI).
	 *
	 * @return string Récap d'exécution (ex.: ce qui a été joué).
	 */
	public function jouer($module, $version, $info)
	{
		if(isset($this->défs))
			foreach($this->défs as $var => $val)
				$$var = $val;
		
		require $info;
		
		$récap = file_get_contents($info, false, null, 0, $this->récapTMax + 1);
		if(strlen($récap) > $this->récapTMax)
			$récap = substr($this->récapTMax, 0, -1)."\n// etc.; taille totale du fichier: ".filesize($info)."; md5(".md5_file($info).")\n";
		return $récap;
	}
	
	public $majeur;
	public $défs;
}

?>
