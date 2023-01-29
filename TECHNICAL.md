# Labdoo Technical Documentation

This document provides technical information about the Labdoo platform, including its architecture, main entities, custom modules, and available Drush commands.

## Project Overview

Labdoo is a collaborative platform built on Drupal 10 that helps manage the collection, refurbishment, and distribution of electronic devices (dootronics) to educational projects worldwide. The platform coordinates volunteers, hubs, and educational villages to facilitate the donation process and track the lifecycle of devices.

## Main Entities

### Dootronics
Electronic devices (laptops, tablets, etc.) that are donated, refurbished, and distributed to educational projects. Dootronics have various states throughout their lifecycle and contain information about their specifications, condition, and location.

### Dootrips
Volunteer trips that help transport dootronics from one location to another, eliminating shipping costs and reducing carbon footprint. Dootrips connect donors with educational projects across geographical boundaries.

### Hubs
Local centers where volunteers collect, refurbish, and prepare dootronics for distribution. Hubs serve as coordination points in the Labdoo network.

### Edoovillages
Educational projects (schools, orphanages, etc.) that receive dootronics. These entities contain information about their location, needs, and the devices they have received.

### Teams
Groups of volunteers working together on specific aspects of the Labdoo project. Teams can be organized around geographical areas, functions, or specific initiatives.

### Users
Platform users with different roles and permissions, including administrators, hub managers, team members, and regular users.

## Custom Modules

### Core Entity Modules

#### labdoo_dootronics
Manages the lifecycle of electronic devices from donation to deployment. Includes functionality for:
- Device registration and inventory management
- Technical specifications tracking
- Status updates and history
- Assignment to edoovillages

#### labdoo_dootrip
Handles the coordination of volunteer trips for transporting devices. Features include:
- Trip registration and planning
- Route visualization on maps
- Device pickup and delivery coordination
- Trip status tracking

#### labdoo_edoovillage
Manages educational projects that receive devices. Functionality includes:
- Project registration and profile management
- Needs assessment and device requests
- Impact tracking and reporting
- Communication with donors and volunteers

#### labdoo_hub
Coordinates local centers for device collection and refurbishment. Features include:
- Hub registration and management
- Inventory tracking
- Volunteer coordination
- Activity reporting

#### labdoo_team
Manages volunteer teams and their activities. Includes:
- Team creation and membership management
- Task assignment and tracking
- Team communication tools
- Activity reporting

### Supporting Modules

#### labdoo_common
Provides shared functionality used across multiple modules, including:
- Utility functions
- Shared services
- Common interfaces
- Base classes for exports and other operations

#### labdoo_notifications
Handles email notifications and communication within the platform:
- Email templates
- Notification triggers
- Contact forms
- Subscription management

#### labdoo_migrate
Tools for migrating data from previous versions of the platform:
- Content synchronization
- User migration
- Permission mapping
- Entity relationship preservation

#### labdoo_statistics
Generates reports and statistics about platform activities:
- Device tracking metrics
- Impact assessment
- User activity reports
- Geographic distribution analysis

#### labdoo_gallery
Manages media content related to projects and activities:
- Photo galleries
- Media organization
- Display options
- Media embedding

#### labdoo_global_action
Coordinates global initiatives and campaigns:
- Action generation for different entity types
- Campaign management
- Global event coordination

#### labdoo_admin_toolbar
Customizes the administrative interface for Labdoo-specific needs:
- Custom menu items
- Quick access to common tasks
- Role-based toolbar customization

#### labdoo_privileges
Manages custom permissions and access control:
- Role-based permissions
- Content access rules
- Operation restrictions

#### labdoo_search_fix
Provides fixes and enhancements for the search functionality:
- Search index optimization
- Custom search filters
- Search result improvements

#### labdoo_user
Extends user functionality with Labdoo-specific features:
- User profiles
- Activity tracking
- Contribution history
- Reputation system

#### labdoo_story
Manages success stories and testimonials:
- Story creation and editing
- Media integration
- Categorization and tagging
- Display options

#### labdoo_wiki
Provides wiki-like documentation functionality:
- Collaborative content creation
- Version history
- Categorization
- Search integration

#### mini_wiki
A lightweight wiki implementation for specific documentation needs:
- Simple page creation
- Basic formatting
- Categorization
- Search integration

#### github_issues
Integrates with GitHub for issue tracking:
- Issue creation from the platform
- Status synchronization
- Comment integration

#### queue_manager
Manages background processing tasks:
- Task queuing
- Scheduled execution
- Failure handling
- Performance optimization

#### simple_slideshow
Provides slideshow functionality for media presentation:
- Image slideshows
- Configuration options
- Display integration

## Drush Commands

### Migration Commands

#### SynchronizerCommands
General content synchronization commands for migrating data.
```
drush migrate:sync [options]
```

#### BasicPageSynchronizerCommands
Commands for migrating basic pages from Drupal 7.
```
drush migrate:sync-page [options]
```

#### StorySynchronizerCommands
Commands for migrating stories.
```
drush migrate:sync-story [options]
```

#### TeamSynchronizerCommands
Commands for migrating team data from Organic Groups.
```
drush migrate:sync-team [options]
```

#### GallerySynchronizerCommands
Commands for migrating gallery content.
```
drush migrate:sync-gallery [options]
```

#### ImportPermissionsCommands
Commands for migrating user permissions.
```
drush migrate:import-permissions [options]
```

#### NodeDeleteCommands
Commands for deleting nodes during migration.
```
drush migrate:delete-node [options]
```

### Entity-specific Commands

#### labdoo_dootrip/ComputeCommands
Commands for dootrip computations and operations.
```
drush dootrip:compute [options]
```

#### labdoo_dootronics/ComputeCommands
Commands for dootronics computations and operations.
```
drush dootronics:compute [options]
```

### Utility Commands

#### labdoo_search_fix/SearchFixCommands
Commands for fixing search API configuration.
```
drush search-fix:rebuild-index [options]
```

## Database Structure

The Labdoo platform uses Drupal's entity system to store data. The main content types are implemented as custom entities with fields for specific attributes. The database follows Drupal's standard schema with additional tables for custom entities and fields.

## API Integration

The platform provides REST API endpoints for integration with external systems. These endpoints allow for:
- Device registration and updates
- Trip coordination
- Project management
- Data retrieval for reporting

## Multilingual Support

Labdoo supports multiple languages through Drupal's translation system. Content can be translated into various languages, and the user interface adapts to the user's preferred language.

## Caching Strategy

The platform uses Redis for caching to improve performance. Cache bins are configured for different types of data, and the cache is rebuilt automatically when content changes.

## Search Functionality

Search is implemented using Search API with custom indexes for different entity types. The search functionality allows users to find devices, projects, trips, and other content based on various criteria.