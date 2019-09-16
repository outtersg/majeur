<?php
/*
 * Copyright (c) 2019 Guillaume Outters
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
 * Diagnostic
 */
class MajeurInfo
{
	public function __construct(Majeur $m)
	{
		$this->majeur = $m;
		if(!$this->majeur->silo)
			throw new Exception('Impossible d\'attacher un MajeurInfo à un Majeur sans silo');
		$this->silo = $this->majeur->silo;
		$this->majeur->silo = $this;
	}
	
	public function vers($chemin, $format = null)
	{
		if(!isset($format) && isset($chemin))
		{
			if(($aprèsPoint = strrchr($chemin, '.')) === false && ($aprèsGuillotine = strrchr($chemin, '/')) === false)
			{
				$format = $chemin;
				$chemin = null;
			}
			else
				$format = substr($aprèsPoint, 1);
		}
		$this->_chemin = $chemin;
		$this->_format = $format;
	}
	
	public function initialiser()
	{
		return $this->silo->initialiser();
	}
	
	public function verrouiller()
	{
		return $this->silo->verrouiller();
	}
	
	public function déjàJouées()
	{
		if(($toutePremièreFois = !isset($this->_graphe)))
		{
			$this->majeur->organiserÀFaire();
			$this->_graphe = $this->majeur->_àFaire;
		}
		
		return $this->silo->déjàJouées();
	}
	
	public function commencer($module, $version)
	{
		if(isset($this->_format))
		{
			$this->_afficher($module, $version);
			/* À FAIRE: ne pas le faire systématiquement. On pourrait avoir un mode "affiche-moi ce que tu vas faire et continue en le faisant". */
			exit;
		}
	}
	
	protected function _afficher($moduleCourant, $versionCourante)
	{
		if(!isset($this->_aff))
		{
			$classe = 'MajeurInfoAff'.ucfirst($this->_format);
			require_once dirname(__FILE__).'/'.$classe.'.php';
			$this->_aff = new $classe($this, $this->_chemin);
		}
		
		$this->_aff->afficher($this->_graphe, array($moduleCourant, $versionCourante), $this->majeur->_àFaire);
	}
}

?>
