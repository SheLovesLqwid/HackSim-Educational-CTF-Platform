# HackSim - Educational CTF Platform


# Being developed rn chat!!! :)

A comprehensive, story driven desktop CTF platform for learning cybersecurity through gamified challenges.

## Project Structure

```
HackSim/
├── HackSim.sln
├── HackSim.API/           # Web API for challenge management and authentication
├── HackSim.Client.UI/     # WPF desktop application
├── HackSim.Core/          # Shared models and services
│   ├── Models/            # Domain models
│   ├── Services/          # Business logic implementations
│   ├── Interfaces/        # Service contracts
│   └── Enums/             # Enumerations
```

## Prerequisites

- .NET 8.0 SDK or later
- Visual Studio 2022 (with .NET desktop and web development workloads)
- SQL Server 2019+ or SQLite

## Getting Started

1. **Clone the repository**
   ```bash
   git clone https://github.com/SheLovesLqwid/hacksim.git
   cd hacksim
   ```

2. **Restore dependencies**
   ```bash
   dotnet restore
   ```

3. **Run the application**
   - For development, run both the API and Client projects
   - The API will be available at `https://localhost:5001`
   - The WPF client will launch as a desktop application

## Development Setup

1. **API Configuration**
   - Update `appsettings.json` with your database connection string
   - Run database migrations:
     ```bash
     cd HackSim.API
     dotnet ef database update
     ```

2. **Client Configuration**
   - Update `appsettings.json` with the API base URL
   - Configure any client-specific settings

## Building for Production

```bash
dotnet publish -c Release -o ./publish
```

## License

MIT License - Copyright (c) 2025 OGDev Studios LLC
