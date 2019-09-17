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
		$this->_calculerDéps();
		
		if(!isset($this->_aff))
		{
			$classe = 'MajeurInfoAff'.ucfirst($this->_format);
			require_once dirname(__FILE__).'/'.$classe.'.php';
			$this->_aff = new $classe($this, $this->_chemin);
		}
		
		$this->_aff->afficher($this->_déps, $this->_graphe, array($moduleCourant, $versionCourante), $this->majeur->_àFaire);
	}
	
	/*- Exploration statique -------------------------------------------------*/
	
	protected function _calculerDéps()
	{
		$this->_déps = array();
		
		foreach($this->_graphe as $module => $versions)
			foreach($versions as $v => $info)
				$this->_explorer($module, $v, $info);
		
		// Recherche récursive des prérequis.
		
		$traités = array();
		$nPréc = -1;
		while(($n = count($this->_déps))) // Tant qu'on a encore à traiter.
		{
			if($n == $nPréc)
				throw new Exception("Boucle infinie entre ".implode(', ', array_keys($this->_déps)));
			foreach($this->_déps as $f => & $pdf) // Pour chacun des fichiers (Pointeur Déps Fichier, pour qui se pose la question).
			{
				$pdf['rr'] = isset($pdf['req']) ? $pdf['req'] : array();
				if(isset($pdf['inc']))
					foreach($pdf['inc'] as $i) // Pour chacune des inclusions.
					{
						if(isset($this->_déps[$i])) // Aïe, pas encore traité.
						{
							// On remballe, pour le prochain tour de boucle.
							unset($pdf['rr']);
							continue 2;
						}
						if(!isset($traités[$i]))
						{
							fprintf(STDERR, "# $f: inclusion introuvable: $i\n");
							$traités[$i] = array('rr' => array()); // Pour qu'il ne plante pas la prochaine fois.
						}
						$pdf['rr'] += $traités[$i]['rr'];
					}
				// Et on passe dans les traités.
				$traités[$f] = $this->_déps[$f];
				unset($this->_déps[$f]);
			}
			$nPréc = $n;
		}
		$this->_déps = $traités;
	}
	
	protected function _explorer($module, $v, $info)
	{
		if(substr($info, -4) == '.sql')
			$this->_explorerSql($module, $v, $info);
		else
			fprintf(STDERR, "[$module $v] Fichier non géré: $info\n");
	}
	
	protected function _explorerSql($module, $v, $f)
	{
		$boulot = array($f);
		
		while(($f = array_shift($boulot)))
		{
			if(isset($this->_déps[$f]))
				continue;
			$this->_déps[$f] = array();
			if(!file_exists($f))
			{
				fprintf(STDERR, "[$module $v] Fichier inexistant: $f\n");
				continue;
			}
			
			$contenu = file_get_contents($f);
			preg_match_all('/^\h*#require\h+(\S+)\h+(\S+)\h*(?:--|$)/m', $contenu, $r);
			foreach($r[1] as $num => $m1)
				$this->_déps[$f]['req'][$m1.' '.$r[2][$num]] = true;
			preg_match_all('/^\h*#include\h+(\S+)\h*(?:--|$)/m', $contenu, $r);
			foreach($r[1] as $num => $m1)
			{
				if(substr($m1, 0, 1) != '/')
					$m1 = dirname($f).'/'.$m1;
				while(($m1racc = preg_replace('#/(?:\.?/|(?:[^./][^/]*|[.][^./][^/]*)/[.]{2}/)+#', '/', $m1)) != $m1)
					$m1 = $m1racc;
				$this->_déps[$f]['inc'][] = $m1;
				$boulot[] = $m1;
			}
		}
	}
}

?>
