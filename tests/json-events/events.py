#!/usr/bin/env python3
# Checks a `benchy --json` stdout capture against the contract (pts-core/static/json-events/v1.schema.json).
#   events.py check <stdout file> <exit code>   every line is JSON, validates, seq gapless, hello first, done last,
#                                               done.exit_code == the exit code, no paths in structured fields
#   events.py q <stdout file> <python expr>     prints the expression's value (JSON) with E = all events,
#                                               T(type) = events of a type, F(type) = first, L(type) = last
# The validator is a small hand-written one for the JSON Schema keywords the schema uses (no jsonschema dependency).
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
SCHEMA_FILE = os.path.join(HERE, '..', '..', 'pts-core', 'static', 'json-events', 'v1.schema.json')
TYPES = {'object': dict, 'array': list, 'string': str, 'boolean': bool, 'null': type(None)}
# Free-text fields may mention paths; structured fields never do
FREE_TEXT = {('log', 'text'), ('error', 'message'), ('error', 'detail'), ('prompt', 'question'), ('install_output', 'text'), ('test_output', 'text'), ('install_end', 'error'), ('test_result', 'error'), ('upload', 'message')}
FREE_TEXT_KEYS = {'description', 'summary', 'title', 'message', 'text', 'question', 'license', 'help', 'label'}
PATH_MARKERS = ('/home/bob', '/bench/', '/share/', 'C:\\', '.benchy')


def is_type(value, name):
	if name == 'integer':
		return isinstance(value, int) and not isinstance(value, bool)
	if name == 'number':
		return isinstance(value, (int, float)) and not isinstance(value, bool)
	return isinstance(value, TYPES[name])


def validate(value, schema, root, path, errors):
	if '$ref' in schema:
		target = root
		for part in schema['$ref'].lstrip('#/').split('/'):
			target = target[part]
		validate(value, target, root, path, errors)
	if 'type' in schema:
		names = schema['type'] if isinstance(schema['type'], list) else [schema['type']]
		if not any(is_type(value, n) for n in names):
			errors.append('%s: %r is not %s' % (path, value, names))
			return
	if 'const' in schema and value != schema['const']:
		errors.append('%s: %r != %r' % (path, value, schema['const']))
	if 'enum' in schema and value not in schema['enum']:
		errors.append('%s: %r not in %r' % (path, value, schema['enum']))
	if 'pattern' in schema and isinstance(value, str) and not re.search(schema['pattern'], value):
		errors.append('%s: %r does not match %s' % (path, value, schema['pattern']))
	if 'minimum' in schema and is_type(value, 'number') and value < schema['minimum']:
		errors.append('%s: %r < %r' % (path, value, schema['minimum']))
	if isinstance(value, dict):
		for key in schema.get('required', []):
			if key not in value:
				errors.append('%s: missing %s' % (path, key))
		for key, sub in schema.get('properties', {}).items():
			if key in value:
				validate(value[key], sub, root, path + '.' + key, errors)
		extra = schema.get('additionalProperties')
		if isinstance(extra, dict):
			for key in value:
				if key not in schema.get('properties', {}):
					validate(value[key], extra, root, path + '.' + key, errors)
	if isinstance(value, list) and 'items' in schema:
		for i, item in enumerate(value):
			validate(item, schema['items'], root, '%s[%d]' % (path, i), errors)
	for sub in schema.get('allOf', []):
		validate(value, sub, root, path, errors)
	if 'if' in schema:
		probe = []
		validate(value, schema['if'], root, path, probe)
		if not probe and 'then' in schema:
			validate(value, schema['then'], root, path, errors)


def load(file):
	events = []
	with open(file, 'rb') as f:
		raw = f.read()
	if raw.startswith(b'\xef\xbb\xbf'):
		raise ValueError('stdout starts with a BOM')
	for n, line in enumerate(raw.decode('utf-8').split('\n')):
		line = line.rstrip('\r')
		if line == '':
			continue
		try:
			event = json.loads(line)
		except ValueError:
			raise ValueError('line %d is not JSON: %r' % (n + 1, line[:200]))
		if not isinstance(event, dict):
			raise ValueError('line %d is not an object' % (n + 1))
		events.append(event)
	return events


def path_leaks(value, where):
	if isinstance(value, str):
		return [where + ': ' + value[:120]] if any(m in value for m in PATH_MARKERS) else []
	if isinstance(value, dict):
		return [l for k, v in value.items() if k not in FREE_TEXT_KEYS for l in path_leaks(v, where + '.' + k)]
	if isinstance(value, list):
		return [l for i, v in enumerate(value) for l in path_leaks(v, '%s[%d]' % (where, i))]
	return []


def check(file, exit_code):
	problems = []
	try:
		events = load(file)
	except ValueError as e:
		return [str(e)]
	if not events:
		return ['no output']
	with open(SCHEMA_FILE) as f:
		schema = json.load(f)
	for i, e in enumerate(events):
		errors = []
		validate(e, schema, schema, '[%d %s]' % (i, e.get('type')), errors)
		problems += errors
		for key, value in e.items():
			if (e.get('type'), key) not in FREE_TEXT and key not in FREE_TEXT_KEYS and key not in ('v', 'type', 'seq', 'ts'):
				problems += path_leaks(value, '[%d %s].%s' % (i, e.get('type'), key))
	if [e.get('seq') for e in events] != list(range(1, len(events) + 1)):
		problems.append('seq is not 1..%d without gaps' % len(events))
	if len(events) == 1 and events[0].get('type') == 'error' and events[0].get('code') == 'unsupported_schema':
		if exit_code != 2:
			problems.append('unsupported_schema must exit 2, got %d' % exit_code)
		return problems
	if events[0].get('type') != 'hello':
		problems.append('first event is %s, not hello' % events[0].get('type'))
	if events[-1].get('type') != 'done':
		problems.append('last event is %s, not done' % events[-1].get('type'))
	elif events[-1].get('exit_code') != exit_code:
		problems.append('done.exit_code %r != process exit code %d' % (events[-1].get('exit_code'), exit_code))
	if sum(1 for e in events if e.get('type') in ('hello', 'done')) != 2:
		problems.append('more than one hello or done')
	run = events[0].get('run')
	if run:
		problems += ['%s event without run %s' % (e.get('type'), run) for e in events[1:] if e.get('run') != run]
	return problems


def main():
	if len(sys.argv) == 4 and sys.argv[1] == 'check':
		problems = check(sys.argv[2], int(sys.argv[3]))
		for p in problems[:20]:
			print('  CONTRACT: ' + p)
		sys.exit(1 if problems else 0)
	if len(sys.argv) == 4 and sys.argv[1] == 'q':
		E = load(sys.argv[2])
		T = lambda t: [e for e in E if e.get('type') == t]
		F = lambda t: (T(t) or [None])[0]
		L = lambda t: (T(t) or [None])[-1]
		try:
			value = eval(sys.argv[3], {'E': E, 'T': T, 'F': F, 'L': L, 'len': len, 'set': set, 'sorted': sorted, 'any': any, 'all': all})
		except Exception as e:
			value = 'ERROR: %s' % e
		print(json.dumps(value))
		return
	print(__doc__ if __doc__ else 'usage: events.py check <file> <exit> | q <file> <expr>')
	sys.exit(2)


if __name__ == '__main__':
	main()
