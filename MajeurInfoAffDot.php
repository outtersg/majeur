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

class MajeurInfoAffDot
{
	public function __construct($informateur, $chemin)
	{
		$this->_sortie = $chemin;
	}
	
	public function afficher($info, $màjs, $courante, $reste)
	{
		if(isset($this->_sortie))
			ob_start();
		
		$reste[$courante[0]][$courante[1]] = $màjs[$courante[0]][$courante[1]]; // La MàJ en train d'être jouée est considérée comme non encore passée.
		
		$idFichiers = array();
				
		foreach($màjs as $app => $versions)
			foreach($versions as $v => $f)
				$idFichiers["$app $v"] = $idFichiers[$f] = preg_replace('/[^a-zA-Z0-9]+/', '_', "$app $v");
		
		echo "digraph G\n{\n\tedge [ dir = back ];\n";
		$idApp = 0;
		foreach($màjs as $app => $versions)
		{
			++$idApp;
			$affApp = $app;
			$affApp = strtr($affApp, array('.' => '\n', ' ' => '\n')); // Des retours à la ligne, pour qu'en cas d'affichage colonne on l'ait aussi étroite que les numéros de version possible.
			echo "\tsubgraph cluster_$idApp\n\t{\n\t\tlabel = \"$affApp\";\n\t\tstyle = filled;\n\t\tcolor = lightgrey;\n\n";
			$nœuds = array($versions, array());
			if(isset($reste[$app]))
			{
				$nœuds[0] = array_diff_key($nœuds[0], $reste[$app]);
				$nœuds[1] = $reste[$app];
			}
			foreach($nœuds as $cat => $trucs)
			{
				$coul = $cat ? 'FFFFBF' : 'BFFFBF';
				echo "\t\tnode [ shape = box, style = filled, fillcolor = \"#$coul\" ]\n";
				foreach($trucs as $v => $f)
				{
					$affV = $v;
					if(preg_match("#^(?:[a-zA-Z]+-)?$v-(.*)\.[a-zA-Z0-9]{1,5}\$#", basename($f), $r)) // On récupère le libellé des noms respectant la convention <préfixe commun>-<version>-<libellé>.<suffixe>.
						$affV .= "\\n\\\"".$r[1]."\\\"";
					echo "\t\t".$idFichiers[$f]." [ label = \"".$affV."\" ]\n";
				}
			}
			unset($suivant);
			$têteEnLAir = array_reverse($versions, true);
			$avant = $idFichiers[array_shift($têteEnLAir)];
			foreach($têteEnLAir as $f)
			{
				echo "\t\t".$avant." -> ".($id = $idFichiers[$f])."\n";
				$avant = $id;
			}
			echo "\t}\n";
		}
		$ids = array();
		// Dans ce qui suit, les nœuds non encore déclarés n'ont pas été trouvés: on les affiche en rouge.
		echo "\tnode [ shape = box, style = filled, fillcolor = \"#FFBFBF\" ]\n";
		foreach($màjs as $app => $versions)
			foreach($versions as $v => $f)
				if(isset($info[$f]))
				{
					if(isset($info[$f]['rr']))
						foreach($info[$f]['rr'] as $r => $trou)
						{
							$idReq = isset($idFichiers[$r]) ? $idFichiers[$r] : '"'.$r.'"';
							echo "\t".$idFichiers[$f]." -> $idReq\n";
						}
				}
		// Les fichiers inclus.
		echo "\tnode [ shape = box, style = filled, fillcolor = \"#FFFFFF\" ]\n";
		echo "\tedge [ style = dotted ];\n";
		foreach($info as $f => $df)
		{
			if(!isset($idFichiers[$f]))
			{
				$n = 0;
				$suffixe = '';
				$id = preg_replace('/[^a-zA-Z0-9]+/', '_', basename($f));
				while(isset($ids[$id.$suffixe]))
					$suffixe = '_'.++$n;
				$ids[$id .= $suffixe] = true;
				$idFichiers[$f] = $id;
				echo "\t$id [ label = \"".basename($f)."\" ]\n";
			}
			else
				$id = $idFichiers[$f];
			if(isset($df['inc']))
				foreach($df['inc'] as $r)
					echo "\t$id -> ".$idFichiers[$r]."\n";
		}
		echo "}\n";
		
		if(isset($this->_sortie))
			file_put_contents($this->_sortie, ob_get_clean());
	}
}

?>
