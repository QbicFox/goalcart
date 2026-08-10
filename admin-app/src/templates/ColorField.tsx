import InputAdornment from '@mui/material/InputAdornment';
import TextField from '@mui/material/TextField';

interface ColorFieldProps {
  label: string;
  value: string;
  onChange: (next: string) => void;
  helperText?: string;
  size?: 'small' | 'medium';
}

/** A hex-color field with a native color picker in the adornment. */
export default function ColorField({
  label,
  value,
  onChange,
  helperText,
  size = 'small',
}: ColorFieldProps) {
  const safe = /^#[0-9a-fA-F]{6}$/.test(value) ? value : '#2271b1';

  return (
    <TextField
      label={label}
      value={value}
      size={size}
      fullWidth
      onChange={(event) => onChange(event.target.value)}
      helperText={helperText}
      slotProps={{
        input: {
          startAdornment: (
            <InputAdornment position="start">
              <input
                type="color"
                value={safe}
                onChange={(event) => onChange(event.target.value)}
                aria-label={label}
                style={{
                  width: 28,
                  height: 28,
                  padding: 0,
                  border: 'none',
                  background: 'transparent',
                  cursor: 'pointer',
                }}
              />
            </InputAdornment>
          ),
        },
      }}
    />
  );
}
