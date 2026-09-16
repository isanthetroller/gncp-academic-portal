import re

with open('tests/selenium/test_runner.py', 'r', encoding='utf-8') as f:
    data = f.read()

marker = 'runner.run_full_pipeline()'
main_start = data.find('if __name__ == "__main__":')
end_pos = data.find(marker, main_start) + len(marker)

clean = data[:end_pos] + '\n'

with open('tests/selenium/test_runner.py', 'w', encoding='utf-8') as f:
    f.write(clean)

print(f'Cleaned. Total chars: {len(clean)}, truncated at char {end_pos}')

# Verify syntax
import py_compile
try:
    py_compile.compile('tests/selenium/test_runner.py', doraise=True)
    print('Syntax OK')
except py_compile.PyCompileError as e:
    print('Syntax ERROR:', e)
