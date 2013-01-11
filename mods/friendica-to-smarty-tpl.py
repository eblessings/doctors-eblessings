#!/usr/bin/python
#
# Script to convert Friendica internal template files into Smarty template files
# Copyright 2012 Zach Prezkuta
# Licensed under GPL v3

import os, re, string

ldelim = '{{'
rdelim = '}}'

def fToSmarty(matches):
	match = matches.group(0)
	if match == '$j':
		return match
	match = string.replace(match, '[', '')
	match = string.replace(match, ']', '')

	ldel = ldelim
	rdel = rdelim
	if match.find("'") > -1:
		match = string.replace(match, "'", '')
		ldel = "'" + ldel
		rdel = rdel + "'"
	elif match.find('"') > -1:
		match = string.replace(match, '"', '')
		ldel = '"' + ldel
		rdel = rdel + '"'

	return ldel + match + rdel


def fix_element(element):
	# Much of the positioning here is important, e.g. if you do element.find('if ') before you do
	# element.find('endif'), then you may get some multiply-replaced delimiters

	if element.find('endif') > -1:
		element = ldelim + '/if' + rdelim
		return element

	if element.find('if ') > -1:
		element = string.replace(element, '{{ if', ldelim + 'if')
		element = string.replace(element, '{{if', ldelim + 'if')
		element = string.replace(element, ' }}', rdelim)
		element = string.replace(element, '}}', rdelim)
		return element

	if element.find('else') > -1:
		element = ldelim + 'else' + rdelim
		return element

	if element.find('endfor') > -1:
		element = ldelim + '/foreach' + rdelim
		return element

	if element.find('for ') > -1:
		element = string.replace(element, '{{ for ', ldelim + 'foreach ')
		element = string.replace(element, '{{for ', ldelim + 'foreach ')
		element = string.replace(element, ' }}', rdelim)
		element = string.replace(element, '}}', rdelim)
		return element

	if element.find('endinc') > -1:
		element = ''
		return element

	if element.find('inc ') > -1:
		parts = element.split(' ')
		element = ldelim + 'include file="'

		# We need to find the file name. It'll either be in parts[1] if the element was written as {{ inc file.tpl }}
		# or it'll be in parts[2] if the element was written as {{inc file.tpl}}
		if parts[0].find('inc') > -1:
			first = 0
		else:
			first = 1

		if parts[first+1][0] == '$':
			# This takes care of elements where the filename is a variable, e.g. {{ inc $file }}
			element += ldelim + parts[first+1].rstrip('}') + rdelim
		else:
			# This takes care of elements where the filename is a path, e.g. {{ inc file.tpl }}
			element += parts[first+1].rstrip('}') 

		element += '"'

		if len(parts) > first + 1 and parts[first+2] == 'with':
			# Take care of variable substitutions, e.g. {{ inc file.tpl with $var=this_var }}
			element += ' ' + parts[first+3].rstrip('}')[1:]

		element += rdelim
		return element


def convert(filename, tofilename, php_tpl):
	for line in filename:
		newline = ''
		st_pos = 0
		brack_pos = line.find('{{')

		if php_tpl:
			# If php_tpl is True, this script will only convert variables in quotes, like '$variable'
			# or "$variable". This is for .tpl files that produce PHP scripts, where you don't want
			# all the PHP variables converted into Smarty variables
			pattern1 = re.compile(r"""
([\'\"]\$\[[a-zA-Z]\w*
(\.
(\d+|[a-zA-Z][\w-]*)
)*
(\|[\w\$:\.]*)*
\][\'\"])
""", re.VERBOSE)
			pattern2 = re.compile(r"""
([\'\"]\$[a-zA-Z]\w*
(\.
(\d+|[a-zA-Z][\w-]*)
)*
(\|[\w\$:\.]*)*
[\'\"])
""", re.VERBOSE)
		else:
			# Compile the pattern for bracket-style variables, e.g. $[variable.key|filter:arg1:arg2|filter2:arg1:arg2]
			# Note that dashes are only allowed in array keys if the key doesn't start
			# with a number, e.g. $[variable.key-id] is ok but $[variable.12-id] isn't
			#
			# Doesn't currently process the argument position key 'x', i.e. filter:arg1:x:arg2 doesn't get
			# changed to arg1|filter:variable:arg2 like Smarty requires
			#
			# Filter arguments can be variables, e.g. $variable, but currently can't have array keys with dashes
			# like filter:$variable.key-name
			pattern1 = re.compile(r"""
(\$\[[a-zA-Z]\w*
(\.
(\d+|[a-zA-Z][\w-]*)
)*
(\|[\w\$:\.]*)*
\])
""", re.VERBOSE)

			# Compile the pattern for normal style variables, e.g. $variable.key
			pattern2 = re.compile(r"""
(\$[a-zA-Z]\w*
(\.
(\d+|[a-zA-Z][\w-]*)
)*
(\|[\w\$:\.]*)*
)
""", re.VERBOSE)

		while brack_pos > -1:
			if brack_pos > st_pos:
				line_segment = line[st_pos:brack_pos]
				line_segment = pattern2.sub(fToSmarty, line_segment)
				newline += pattern1.sub(fToSmarty, line_segment)

			end_brack_pos = line.find('}}', brack_pos)
			if end_brack_pos < 0:
				print "Error: no matching bracket found"

			newline += fix_element(line[brack_pos:end_brack_pos + 2])
			st_pos = end_brack_pos + 2

			brack_pos = line.find('{{', st_pos)

		line_segment = line[st_pos:]
		line_segment = pattern2.sub(fToSmarty, line_segment)
		newline += pattern1.sub(fToSmarty, line_segment)
		newline = newline.replace("{#", ldelim + "*")
		newline = newline.replace("#}", "*" + rdelim)
		tofilename.write(newline)


path = raw_input('Path to template folder to convert: ')
if path[-1:] != '/':
	path = path + '/'

outpath = path + 'smarty3/'

if not os.path.exists(outpath):
	os.makedirs(outpath)

files = os.listdir(path)
for a_file in files:
	if a_file == 'htconfig.tpl':
		php_tpl = True
	else:
		php_tpl = False

	filename = os.path.join(path,a_file)
	ext = a_file.split('.')[-1]
	if os.path.isfile(filename) and ext == 'tpl':
		f = open(filename, 'r')

		newfilename = os.path.join(outpath,a_file)
		outf = open(newfilename, 'w')

		print "Converting " + filename + " to " + newfilename
		convert(f, outf, php_tpl)

		outf.close()
		f.close()

