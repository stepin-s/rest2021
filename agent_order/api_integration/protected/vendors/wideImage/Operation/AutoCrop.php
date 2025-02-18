<?php
	
	
	
	class WideImage_Operation_AutoCrop
	{
		
		function execute($img, $margin, $rgb_threshold, $pixel_cutoff, $base_color)
		{
			$margin = intval($margin);
			
			$rgb_threshold = intval($rgb_threshold);
			if ($rgb_threshold < 0)
				$rgb_threshold = 0;
			
			$pixel_cutoff = intval($pixel_cutoff);
			if ($pixel_cutoff <= 1)
				$pixel_cutoff = 1;
			
			if ($base_color === null)
				$rgb_base = $img->getRGBAt(0, 0);
			else
			{
				if ($base_color < 0)
					return $img->copy();
				
				$rgb_base = $img->getColorRGB($base_color);
			}
			
			$cut_rect = array('left' => 0, 'top' => 0, 'right' => $img->getWidth() - 1, 'bottom' => $img->getHeight() - 1);
			for ($y = 0; $y <= $cut_rect['bottom']; $y++)
			{
				$count = 0;
				for ($x = 0; $x <= $cut_rect['right']; $x++)
				{
					$rgb = $img->getRGBAt($x, $y);
					$diff = abs($rgb['red'] - $rgb_base['red']) + abs($rgb['green'] - $rgb_base['green']) + abs($rgb['blue'] - $rgb_base['blue']);
					if ($diff > $rgb_threshold)
					{
						$count++;
						if ($count >= $pixel_cutoff)
						{
							$cut_rect['top'] = $y;
							break 2;
						}
					}
				}
			}
			
			for ($y = $img->getHeight() - 1; $y >= $cut_rect['top']; $y--)
			{
				$count = 0;
				for ($x = 0; $x <= $cut_rect['right']; $x++)
				{
					$rgb = $img->getRGBAt($x, $y);
					$diff = abs($rgb['red'] - $rgb_base['red']) + abs($rgb['green'] - $rgb_base['green']) + abs($rgb['blue'] - $rgb_base['blue']);
					if ($diff > $rgb_threshold)
					{
						$count++;
						if ($count >= $pixel_cutoff)
						{
							$cut_rect['bottom'] = $y;
							break 2;
						}
					}
				}
			}
			
			for ($x = 0; $x <= $cut_rect['right']; $x++)
			{
				$count = 0;
				for ($y = $cut_rect['top']; $y <= $cut_rect['bottom']; $y++)
				{
					$rgb = $img->getRGBAt($x, $y);
					$diff = abs($rgb['red'] - $rgb_base['red']) + abs($rgb['green'] - $rgb_base['green']) + abs($rgb['blue'] - $rgb_base['blue']);
					if ($diff > $rgb_threshold)
					{
						$count++;
						if ($count >= $pixel_cutoff)
						{
							$cut_rect['left'] = $x;
							break 2;
						}
					}
				}
			}
			for ($x = $cut_rect['right']; $x >= $cut_rect['left']; $x--)
			{
				$count = 0;
				for ($y = $cut_rect['top']; $y <= $cut_rect['bottom']; $y++)
				{
					$rgb = $img->getRGBAt($x, $y);
					$diff = abs($rgb['red'] - $rgb_base['red']) + abs($rgb['green'] - $rgb_base['green']) + abs($rgb['blue'] - $rgb_base['blue']);
					if ($diff > $rgb_threshold)
					{
						$count++;
						if ($count >= $pixel_cutoff)
						{
							$cut_rect['right'] = $x;
							break 2;
						}
					}
				}
			}
			$cut_rect = array(
					'left' => $cut_rect['left'] - $margin,
					'top' => $cut_rect['top'] - $margin,
					'right' => $cut_rect['right'] + $margin,
					'bottom' => $cut_rect['bottom'] + $margin
				);
			if ($cut_rect['left'] < 0)
				$cut_rect['left'] = 0;
			if ($cut_rect['top'] < 0)
				$cut_rect['top'] = 0;
			if ($cut_rect['right'] >= $img->getWidth())
				$cut_rect['right'] = $img->getWidth() - 1;
			if ($cut_rect['bottom'] >= $img->getHeight())
				$cut_rect['bottom'] = $img->getHeight() - 1;
			return $img->crop($cut_rect['left'], $cut_rect['top'], $cut_rect['right'] - $cut_rect['left'] + 1, $cut_rect['bottom'] - $cut_rect['top'] + 1);
		}
	}
