import Button from '@mui/material/Button';
import Box from '@mui/material/Box';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import { Link as RouterLink } from 'react-router-dom';

/** 404 fallback for unknown hash routes. */
export default function NotFound() {
  return (
    <Box sx={{ py: 6 }}>
      <Paper variant="outlined" sx={{ p: 5, textAlign: 'center' }}>
        <Typography variant="h5" component="h2" gutterBottom>
          {__('Page not found', 'goalcart')}
        </Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2.5 }}>
          {__('The page you are looking for does not exist.', 'goalcart')}
        </Typography>
        <Button component={RouterLink} to="/dashboard" variant="contained">
          {__('Back to Dashboard', 'goalcart')}
        </Button>
      </Paper>
    </Box>
  );
}
