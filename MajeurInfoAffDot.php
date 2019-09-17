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
		
		$reste[$courante[0]][$courante[1]] = true; // La MàJ en train d'être jouée est considérée comme non encore passée.
		
		echo "digraph G\n{\n\tedge [ dir = back ];\n";
		$idApp = 0;
		foreach($màjs as $app => $versions)
		{
			++$idApp;
			$affApp = $app;
			$affApp = strtr($affApp, array('.' => '\n', ' ' => '\n')); // Des retours à la ligne, pour qu'en cas d'affichage colonne on l'ait aussi étroite que les numéros de version possible.
			echo "\tsubgraph cluster_$idApp\n\t{\n\t\tlabel = \"$affApp\";\n\t\tstyle = filled;\n\t\tcolor = lightgrey;\n\n";
			if(isset($reste[$app]))
			{
				echo "\t\tnode [ shape = box, style = filled, fillcolor = \"#FFFFBF\" ];";
				foreach($reste[$app] as $v => $rien)
					echo "\t\t\"$app $v\";";
			}
			echo "\t\tnode [ shape = box, style = filled, fillcolor = \"#BFFFBF\" ];";
			unset($avant);
			foreach(array_reverse($versions, true) as $v => $f)
			{
				echo "\t\t\"$app $v\" [ label = \"$v\" ];\n";
				if(isset($avant))
					echo "\t\t\"$app $avant\" -> \"$app $v\";\n";
				$avant = $v;
			}
			echo "\t}\n";
		}
		echo "}\n";
		
		if(isset($this->_sortie))
			file_put_contents($this->_sortie, ob_get_flush());
	}
}

?>
